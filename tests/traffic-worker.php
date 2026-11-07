<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Hachitechs\HideguardPhp\Access\ResourceRef;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardWordPress\WpdbStorage;

$storage = WpdbStorage::forSite();
try {
    if ($argv[1] === 'probe') {
        \Hachitechs\HideguardWordPress\WpdbCircuitBreakerStore::forSite()->acquire(
            $argv[2],
            130,
            new \Hachitechs\HideguardPhp\Configuration\CircuitBreakerPolicy(threshold: 1)
        );
    } elseif ($argv[1] === 'exclusive') {
        $storage->run('partition', static fn() => null);
    } else {
        $resource = new ResourceRef('cart', $argv[3]);
        $storage->runBrowser(
            'partition',
            $argv[2],
            $resource,
            fn() => $storage->bindResource('partition', $argv[2], $resource)
        );
    }
    echo 'done';
} catch (\Hachitechs\HideguardPhp\Exceptions\ApiException) {
    echo 'open';
} catch (GuardException $error) {
    echo $error->reason->value;
}
