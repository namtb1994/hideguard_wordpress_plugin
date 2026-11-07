<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$core = Composer\InstalledVersions::getInstallPath('hachitechs/hideguard-php');
foreach (['runtime.js', 'runtime.styles.json', 'runtime.less'] as $name) {
    $source = $core . '/browser/dist/' . $name;
    $destination = dirname(__DIR__) . '/assets/' . $name;
    if (in_array('--check', $argv, true)) {
        if (!is_file($destination) || hash_file('sha256', $source) !== hash_file('sha256', $destination)) {
            throw new RuntimeException('Stale WordPress browser artifact: ' . $name);
        }
    } elseif (!copy($source, $destination)) {
        throw new RuntimeException('Unable to publish browser artifact: ' . $name);
    }
}
echo "PASS: WordPress browser artifacts match the installed core.\n";
