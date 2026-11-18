<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$core = Composer\InstalledVersions::getInstallPath('hachitechs/hideguard-php');
foreach (['runtime.js', 'runtime.styles.json', 'runtime.less'] as $name) {
    $source = $core . '/browser/dist/' . $name;
    $destination = dirname(__DIR__) . '/assets/' . $name;
    $contents = file_get_contents($source);
    if ($contents === false) {
        throw new RuntimeException('Unable to read HideGuard browser artifact: ' . $name);
    }
    if ($name === 'runtime.js') {
        // Use the WordPress channel name each time Composer publishes the SDK runtime.
        $contents = preg_replace(
            '/^( +channel = new BroadcastChannel\(")[^"]+("\);)$/m',
            '${1}hachitech-hideguard${2}',
            $contents,
            -1,
            $count
        );
        if ($contents === null || $count !== 1) {
            throw new RuntimeException('Expected exactly one HideGuard broadcast channel in the SDK runtime.');
        }
    }
    if (in_array('--check', $argv, true)) {
        if (!is_file($destination) || file_get_contents($destination) !== $contents) {
            throw new RuntimeException('Stale WordPress browser artifact: ' . $name);
        }
    } elseif (file_put_contents($destination, $contents) !== strlen($contents)) {
        throw new RuntimeException('Unable to publish browser artifact: ' . $name);
    }
}
echo "PASS: WordPress browser artifacts match the expected SDK output.\n";
