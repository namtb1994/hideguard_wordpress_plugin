<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$run = static function (array $command, string $directory): void {
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $directory);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Plugin build command failed.');
    }
};
$run([PHP_BINARY, 'bin/publish-assets.php', '--check'], $root);
$staging = $root . '/build/staging-' . bin2hex(random_bytes(6));
mkdir($staging, 0775, true);
$copyTree = static function (string $source, string $destination): void {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    mkdir($destination, 0775, true);
    foreach ($items as $item) {
        $target = $destination . '/' . substr($item->getPathname(), strlen($source) + 1);
        if ($item->isDir()) {
            mkdir($target, 0775, true);
        } elseif (!copy($item->getPathname(), $target)) {
            throw new RuntimeException('Unable to stage plugin file.');
        }
    }
};
foreach (['src', 'views', 'assets', 'docs'] as $directory) {
    $copyTree($root . '/' . $directory, $staging . '/' . $directory);
}
foreach (['hachitechs-hideguard.php', '.htaccess', 'README.md', 'composer.json', 'composer.lock'] as $name) {
    copy($root . '/' . $name, $staging . '/' . $name);
}
$run(['composer', 'install', '--no-dev', '--no-interaction', '--no-progress',
    '--no-scripts', '--no-plugins', '--optimize-autoloader'], $staging);
// Source installs can fall back to a Git checkout. Honor the core's own archive exclusions.
$core = $staging . '/vendor/hachitechs/hideguard-php';
$archiveDirectory = $staging . '/core-archive';
mkdir($archiveDirectory);
$run(['composer', 'archive', '--format=zip', '--file=core', '--dir=' . $archiveDirectory], $core);
$archive = new ZipArchive();
if ($archive->open($archiveDirectory . '/core.zip') !== true) {
    throw new RuntimeException('Unable to open the core production archive.');
}
rename($core, $root . '/build/core-source-' . bin2hex(random_bytes(6)));
mkdir($core);
if (!$archive->extractTo($core)) {
    throw new RuntimeException('Unable to extract the core production archive.');
}
$archive->close();
unlink($archiveDirectory . '/core.zip');
rmdir($archiveDirectory);
$destination = $root . '/build/hachitechs-hideguard-1.6.0.zip';
$zip = new ZipArchive();
if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Unable to create plugin ZIP.');
}
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $relative = substr($file->getPathname(), strlen($staging) + 1);
    if ($file->isFile() && !preg_match('#(^|/)\.git(/|$)#', $relative)) {
        $zip->addFile($file->getPathname(), 'hachitechs-hideguard/' . $relative);
    }
}
$zip->close();
echo 'Installable plugin ZIP: ' . $destination . PHP_EOL;
