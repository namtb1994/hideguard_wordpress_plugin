<?php

declare(strict_types=1);

use Hachitechs\HideguardPhp\Access\AccessContext;
use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\HideguardPhp\Configuration\RateLimits;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardPhp\Guard;
use Hachitechs\HideguardWordPress\DedicatedWpdb;
use Hachitechs\HideguardWordPress\WpdbStorage;

require dirname(__DIR__) . '/vendor/autoload.php';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
require getenv('HG_WP_ROOT') . '/wp-load.php';
if (!preg_match('/^hg_wp_test_[a-zA-Z0-9_]+$/D', DB_NAME)) {
    throw new RuntimeException('Refuse workers outside the disposable fixture.');
}
$storage = new WpdbStorage(new DedicatedWpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST), $argv[1]);
$guard = Guard::factory()->withStorage($storage)->make();
try {
    $guard->preflight(new AccessContext('concurrent', $argv[2] ?? 'shared'), new ProtectionPolicy(
        enabled: true, publicKey: 'key', limits: new RateLimits(scopeRequests: isset($argv[3]) ? 1000 : 3, identityRequests: 1000, sessionCapacity: isset($argv[3]) ? 3 : 10000)
    ));
    echo 'accepted';
} catch (GuardException $error) {
    echo $error->reason->value;
}
