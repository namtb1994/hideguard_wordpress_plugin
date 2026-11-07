<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

use Hachitechs\HideguardPhp\Contracts\GuardContract;
use Hachitechs\HideguardPhp\Guard;
use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardPhp\Http\CircuitBreakingTransport;
use Hachitechs\HideguardPhp\Support\SystemClock;
use Hachitechs\HideguardPhp\Verification\Ed25519Verifier;
use Hachitechs\HideguardPhp\VerdictClient;

/** Platform dependency assembly; the core owns every permission decision. */
final class Services
{
    private ?GuardContract $guard = null;
    private ?WpdbStorage $storage = null;
    private ?BackendVerdicts $backend = null;
    private ?PayPalOrders $paypal = null;

    public function __construct(public readonly Settings $settings, public readonly Identity $identity)
    {
    }

    public function guard(): GuardContract
    {
        if ($this->guard === null) {
            $this->guard = Guard::factory()->withStorage($this->storage())
                ->withVerifier(new Ed25519Verifier())->make();
        }
        return $this->guard;
    }

    public function storage(): WpdbStorage
    {
        if ((int)get_option('hachitechs_hideguard_schema', 0) < WpdbStorage::SCHEMA_VERSION) {
            throw new GuardException(FailureReason::VerificationUnavailable, 'HideGuard storage upgrade required.');
        }
        return $this->storage ??= WpdbStorage::forSite();
    }

    public function backend(): BackendVerdicts
    {
        return $this->backend ??= new BackendVerdicts($this);
    }

    public function paypal(): PayPalOrders
    {
        return $this->paypal ??= new PayPalOrders($this);
    }

    public function verdictClient(?WordPressTransport $transport = null): VerdictClient
    {
        return new VerdictClient(
            $this->settings->policy()->endpointUrl,
            $this->settings->apiKey(),
            new CircuitBreakingTransport(
                $transport ?? new WordPressTransport(),
                WpdbCircuitBreakerStore::forSite(),
                new SystemClock(),
                (new RuntimeSettings())->circuit(),
                (string)get_current_blog_id()
            )
        );
    }
}
