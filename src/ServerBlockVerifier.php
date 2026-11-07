<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

use Hachitechs\HideguardPhp\Access\AccessContext;
use Hachitechs\HideguardPhp\Access\BrowserState;
use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\HideguardPhp\Contracts\VerdictVerifier;
use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardPhp\Verification\VerifiedVerdict;

/** One server-observed Block only; never used by the browser verdict endpoint. */
final class ServerBlockVerifier implements VerdictVerifier
{
    public function __construct(
        private readonly WpdbStorage $storage,
        private readonly BrowserState $snapshot,
        private readonly int $observedAt
    ) {
    }

    public function verify(array $payload, AccessContext $context, ProtectionPolicy $policy): VerifiedVerdict
    {
        // Guard calls this while holding its scope transaction, after the HTTP request has finished.
        $current = $this->storage->session($context->scopeId, $context->browserId);
        if (
            $current === null || $this->snapshot->visitorId === null
            || $current->visitorId !== $this->snapshot->visitorId
            || $current->actorId !== $context->actorId || $current->actorId !== $this->snapshot->actorId
            || $current->policyHash !== $this->snapshot->policyHash
            || $current->policyHash !== $policy->fingerprint()
        ) {
            throw new GuardException(
                FailureReason::PermissionDenied,
                'Browser verification changed. Retry verification.'
            );
        }
        return new VerifiedVerdict('block', $this->snapshot->visitorId, true, $this->observedAt);
    }
}
