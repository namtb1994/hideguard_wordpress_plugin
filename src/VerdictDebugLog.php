<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\HideguardPhp\Exceptions\ApiException;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardPhp\Verification\VerdictPolicy;

/** One bounded, redacted PHP log entry per actual backend API attempt. */
final class VerdictDebugLog
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function record(
        string $event,
        string $visitorId,
        ProtectionPolicy $policy,
        WordPressTransport $transport,
        ?array $response,
        ?\Throwable $failure
    ): void {
        try {
            $http = $transport->debugMetadata();
            if (!$this->settings->debugLogging() || $http === null) {
                return;
            }
            $error = $http['transport_error'];
            if ($error === null && $failure !== null && !$failure instanceof ActionDenied) {
                $error = match (true) {
                    $failure instanceof ApiException => $http['http_status'] >= 200 && $http['http_status'] < 300
                        ? 'invalid_response' : 'http_error',
                    $failure instanceof GuardException => $failure->reason->value,
                    default => 'internal_error',
                };
            }
            $blocked = $failure instanceof ActionDenied && $failure->decision->status === 'blocked';
            $entry = [
                'time' => gmdate('c'), 'request_id' => wp_generate_uuid4(), 'event' => $event,
                'environment' => $policy->environment, 'method' => 'POST',
                'url' => $policy->endpointUrl . '/api/v1/verdict',
                'visitor_hash' => hash_hmac('sha256', $visitorId, wp_salt('auth')),
                'http_status' => $http['http_status'], 'duration_ms' => $http['duration_ms'],
                'api_verdict' => VerdictPolicy::groupFor($response['verdict'] ?? null),
                'outcome' => $failure === null ? 'allowed' : ($blocked ? 'blocked' : 'rejected'),
                'error' => $error,
            ];
            error_log('[HideGuard debug] ' . json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // Debug output must never change the protection decision or mask its original exception.
        }
    }
}
