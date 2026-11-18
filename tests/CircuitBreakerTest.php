<?php

declare(strict_types=1);

use Hachitechs\HideguardPhp\Configuration\CircuitBreakerPolicy;
use Hachitechs\HideguardPhp\Contracts\HttpTransport;
use Hachitechs\HideguardPhp\Exceptions\ApiException;
use Hachitechs\HideguardPhp\Http\CircuitBreakingTransport;
use Hachitechs\HideguardPhp\Http\HttpResponse;
use Hachitechs\HideguardWordPress\Identity;
use Hachitechs\HideguardWordPress\Services;
use Hachitechs\HideguardWordPress\Settings;
use Hachitechs\HideguardWordPress\WpdbCircuitBreakerStore;
use Psr\Clock\ClockInterface;

final class CircuitBreakerTest extends AdapterTestCase
{
    public function testOutageStopsAfterFiveCallsAndCredentialsHaveSeparateCircuits(): void
    {
        $transport = new class implements HttpTransport {
            public int $calls = 0;
            public function post(string $url, array $headers, string $body): HttpResponse
            {
                $this->calls++;
                return new HttpResponse(503, '{}');
            }
        };
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('@100');
            }
        };
        $guarded = new CircuitBreakingTransport(
            $transport,
            WpdbCircuitBreakerStore::forSite(),
            $clock,
            new CircuitBreakerPolicy(),
            'fixture'
        );
        for ($i = 0; $i < 100; $i++) {
            try {
                $guarded->post('https://hideguard.io/api/v1/verdict', ['x-api-key' => 'secret-a'], '{}');
            } catch (ApiException $error) {
                self::assertSame(503, $error->statusCode);
            }
        }
        self::assertSame(5, $transport->calls);
        $guarded->post('https://hideguard.io/api/v1/verdict', ['x-api-key' => 'secret-b'], '{}');
        self::assertSame(6, $transport->calls);
    }

    public function testRecoveryProbeLeaseAndStaleResultsAreFenced(): void
    {
        $a = WpdbCircuitBreakerStore::forSite();
        $b = WpdbCircuitBreakerStore::forSite();
        $policy = new CircuitBreakerPolicy(threshold: 1);
        $key = hash('sha256', 'probe');
        $old = $a->acquire($key, 100, $policy);
        $a->record($key, $old, true, 100, $policy);
        $probe = $b->acquire($key, 130, $policy);
        try {
            $a->acquire($key, 131, $policy);
            self::fail('Only one worker may probe.');
        } catch (ApiException) {
            self::assertNotNull($probe->probeToken);
        }
        $replacement = $a->acquire($key, 145, $policy);
        $b->record($key, $probe, false, 145, $policy);
        $b->record($key, $old, false, 145, $policy);
        try {
            $b->acquire($key, 146, $policy);
            self::fail('A stale response cannot release the newer probe.');
        } catch (ApiException) {
            self::assertNotSame($probe->probeToken, $replacement->probeToken);
        }
        $a->record($key, $replacement, false, 146, $policy);
        self::assertNull($b->acquire($key, 147, $policy)->probeToken);
    }

    public function testTwentyWorkersShareExactlyOneRecoveryProbe(): void
    {
        $store = WpdbCircuitBreakerStore::forSite();
        $key = hash('sha256', 'parallel-probes');
        $policy = new CircuitBreakerPolicy(threshold: 1);
        $permit = $store->acquire($key, 100, $policy);
        $store->record($key, $permit, true, 100, $policy);
        $processes = [];
        for ($i = 0; $i < 20; $i++) {
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/traffic-worker.php', 'probe', $key],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $results[] = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
        }
        self::assertSame(1, count(array_filter($results, static fn($r) => $r === 'done')));
        self::assertSame(19, count(array_filter($results, static fn($r) => $r === 'open')));
    }

    public function testOpenCircuitStopsBackendActionWithoutCreatingBanOrCallingHttp(): void
    {
        $settings = new Settings();
        $values = $settings->values();
        $values['keys']['apiKey'] = 'fixture-circuit-secret';
        update_option(Settings::OPTION, $values);
        $services = new Services($settings, new Identity());
        $context = $services->identity->context();
        $attempt = $services->guard()->preflight($context, $settings->policy());
        $services->guard()->report($context, $settings->policy(), SignedPayload::payload(), $attempt->verificationId);
        $calls = 0;
        $mock = static function () use (&$calls): WP_Error {
            $calls++;
            return new WP_Error('fixture', 'outage');
        };
        add_filter('pre_http_request', $mock, 1);
        try {
            for ($i = 0; $i < 8; $i++) {
                try {
                    (new Services($settings, new Identity()))->backend()->assertAllowed('checkout');
                    self::fail('An unavailable API must stop the native action.');
                } catch (ApiException) {
                    self::assertSame('allowed', $services->guard()->inspect($context, $settings->policy())->status);
                }
            }
        } finally {
            remove_filter('pre_http_request', $mock, 1);
        }
        self::assertSame(5, $calls);
        self::assertSame(0, $services->storage()->session($context->scopeId, $context->browserId)->blockedUntil);
    }
}
