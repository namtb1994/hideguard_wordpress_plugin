<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use WP_REST_Response;

final class RestErrors
{
    public static function response(\Throwable $error): WP_REST_Response
    {
        if ($error instanceof ActionDenied) {
            $decision = $error->decision;
            $response = new WP_REST_Response($decision->toBrowserArray(), $decision->status === 'blocked' ? 401 : 403);
            $response->header('Cache-Control', 'private, no-store, max-age=0');
            if ($decision->retryAfterSeconds > 0) {
                $response->header('Retry-After', (string)$decision->retryAfterSeconds);
            }
            return $response;
        }
        if (!$error instanceof GuardException) {
            error_log('HideGuard request failed: ' . $error->getMessage());
            return new WP_REST_Response(['error' => 'Unable to verify access.'], 503);
        }
        $response = new WP_REST_Response(['error' => $error->getMessage()], match ($error->reason) {
            FailureReason::InvalidConfiguration, FailureReason::InvalidVerdict => 400,
            FailureReason::InvalidIdentity, FailureReason::Disabled => 409,
            FailureReason::PermissionDenied => 403,
            FailureReason::RateLimited => 429,
            FailureReason::VerificationUnavailable, FailureReason::Busy, FailureReason::CapacityReached => 503,
        });
        if ($error->retryAfterSeconds > 0) {
            $response->header('Retry-After', (string)$error->retryAfterSeconds);
        }
        return $response;
    }
}
