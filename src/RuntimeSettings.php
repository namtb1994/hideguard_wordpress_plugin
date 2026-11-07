<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

use Hachitechs\HideguardPhp\Configuration\CircuitBreakerPolicy;
use Hachitechs\HideguardPhp\Configuration\RateLimits;
use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\HideguardPhp\Exceptions\GuardException;

/** Deployment-only limits; options and Admin POST cannot alter technical policy. */
final class RuntimeSettings
{
    public const LIMITS = [
        'scope_requests' => 600,
        'identity_requests' => 60,
        'session_capacity' => 10000,
        'session_retention_seconds' => 2592000,
    ];
    public const RATE_WINDOW = 60;
    public const CIRCUIT_THRESHOLD = 5;
    public const CIRCUIT_WINDOW = 60;
    public const CIRCUIT_OPEN = 30;
    public const CIRCUIT_LEASE = 15;

    public function limits(): RateLimits
    {
        $overrides = defined('HACHITECHS_HIDEGUARD_LIMITS') ? HACHITECHS_HIDEGUARD_LIMITS : [];
        return self::resolveLimits($overrides);
    }

    public static function resolveLimits(mixed $overrides): RateLimits
    {
        if (!is_array($overrides) || array_diff(array_keys($overrides), array_keys(self::LIMITS))) {
            throw new GuardException(FailureReason::InvalidConfiguration, 'Invalid deployment limits.');
        }
        $limits = array_replace(self::LIMITS, $overrides);
        foreach ($limits as &$value) {
            $digits = is_int($value) || is_string($value) ? ltrim(trim((string)$value), '0') : '';
            if (
                $digits === '' || !ctype_digit($digits) || strlen($digits) > 10
                || (strlen($digits) === 10 && strcmp($digits, '2147483647') > 0)
            ) {
                throw new GuardException(
                    FailureReason::InvalidConfiguration,
                    'Limits must be integers from 1 to 2147483647.'
                );
            }
            $value = (int)$digits;
        }
        unset($value);
        return new RateLimits(
            scopeRequests: $limits['scope_requests'],
            identityRequests: $limits['identity_requests'],
            windowSeconds: self::RATE_WINDOW,
            sessionCapacity: $limits['session_capacity'],
            sessionRetentionSeconds: $limits['session_retention_seconds']
        );
    }

    public function circuit(): CircuitBreakerPolicy
    {
        return new CircuitBreakerPolicy(
            threshold: self::CIRCUIT_THRESHOLD,
            windowSeconds: self::CIRCUIT_WINDOW,
            openSeconds: self::CIRCUIT_OPEN,
            probeLeaseSeconds: self::CIRCUIT_LEASE
        );
    }
}
