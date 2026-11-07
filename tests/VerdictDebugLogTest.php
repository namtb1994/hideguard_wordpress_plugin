<?php

declare(strict_types=1);

use Hachitechs\HideguardPhp\Access\ResourceRef;
use Hachitechs\HideguardPhp\Exceptions\ApiException;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardWordPress\ActionDenied;
use Hachitechs\HideguardWordPress\ActionEvents;
use Hachitechs\HideguardWordPress\Identity;
use Hachitechs\HideguardWordPress\Services;
use Hachitechs\HideguardWordPress\Settings;
use Hachitechs\HideguardWordPress\VerdictDebugLog;
use Hachitechs\HideguardWordPress\WordPressTransport;

final class VerdictDebugLogTest extends AdapterTestCase
{
    private Services $services;
    private string $logFile;
    private string $previousLog;
    private ?Closure $httpMock = null;
    private int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->services = new Services(new Settings(), new Identity());
        $values = $this->services->settings->values();
        $values['keys']['development']['apiKey'] = 'private-api-key-DO-NOT-LOG';
        update_option(Settings::OPTION, $values);
        $this->logFile = tempnam(sys_get_temp_dir(), 'hg-debug-');
        $this->previousLog = (string)ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLog);
        if ($this->httpMock !== null) {
            remove_filter('pre_http_request', $this->httpMock, 1);
        }
        unlink($this->logFile);
        unset($this->services);
        parent::tearDown();
    }

    /** @dataProvider verdicts */
    public function testDisabledDoesNotLogSuccessBlockOrFailure(string $verdict): void
    {
        $this->grant();
        $this->mock($verdict === 'error' ? new WP_Error('timeout', 'private detail') : $this->response($verdict));
        $this->check();
        self::assertSame(1, $this->calls);
        self::assertSame([], $this->entries());
    }

    public static function verdicts(): array
    {
        return [['allow'], ['block'], ['error']];
    }

    /** @dataProvider responses */
    public function testLogsSafeMetadataAndPreservesDecisions(
        string $body,
        int $status,
        ?string $verdict,
        string $outcome,
        ?string $errorCode
    ): void {
        $this->enable();
        $this->grant();
        $this->mock(self::http($body, $status));
        $error = $this->check();
        self::assertSame($outcome === 'allowed', $error === null);
        $entries = $this->entries();
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertTrue(wp_is_uuid($entry['request_id'], 4));
        self::assertSame('checkout', $entry['event']);
        self::assertSame('development', $entry['environment']);
        self::assertSame('POST', $entry['method']);
        self::assertSame('https://dev.hideguard.io/api/v1/verdict', $entry['url']);
        self::assertSame(hash_hmac('sha256', 'fixture-visitor', wp_salt('auth')), $entry['visitor_hash']);
        self::assertSame($status, $entry['http_status']);
        self::assertGreaterThanOrEqual(0, $entry['duration_ms']);
        self::assertSame($verdict, $entry['api_verdict']);
        self::assertSame($outcome, $entry['outcome']);
        self::assertSame($errorCode, $entry['error']);
        self::assertSame($outcome === 'blocked', $this->services->guard()->inspect(
            $this->services->identity->context(), $this->services->settings->policy()
        )->status === 'blocked');
        $raw = file_get_contents($this->logFile);
        foreach (['fixture-visitor', 'private-api-key', 'private-response-data', 'x-api-key', 'visitorId'] as $secret) {
            self::assertStringNotContainsString($secret, $raw);
        }
    }

    public static function responses(): array
    {
        $valid = static fn(string $verdict): string => json_encode([
            'visitorId' => 'fixture-visitor', 'verdict' => $verdict, 'score' => 0,
            'reasons' => [["code" => "fixtureReason", "detail" => "private-response-data\nforged log"]],
        ], JSON_THROW_ON_ERROR);
        return [
            'allow' => [$valid('allow'), 201, 'allow', 'allowed', null],
            'challenge' => [$valid('challenge'), 200, 'challenge', 'allowed', null],
            'block' => [$valid('block'), 200, 'block', 'blocked', null],
            'unauthorized' => ['private-response-data', 401, null, 'rejected', 'http_error'],
            'server error' => ['private-response-data', 503, null, 'rejected', 'http_error'],
            'invalid JSON' => ["invalid\xff", 200, null, 'rejected', 'invalid_response'],
            'scalar' => ['null', 200, null, 'rejected', 'invalid_response'],
            'list' => ['[]', 200, null, 'rejected', 'invalid_verdict'],
            'wrong visitor' => ['{"visitorId":"wrong","verdict":"allow"}', 200, 'allow', 'rejected', 'invalid_verdict'],
            'unknown verdict' => [$valid("private-response-data\n"), 200, null, 'rejected', 'invalid_verdict'],
            'oversized' => [str_repeat('x', 65537), 200, null, 'rejected', 'response_too_large'],
        ];
    }

    public function testTransportErrorsExcludeProxyDetailsAndHaveNoHttpStatus(): void
    {
        $this->enable();
        $this->grant();
        $this->mock(new WP_Error('timeout', "private-proxy-password\nforged log"));
        self::assertInstanceOf(ApiException::class, $this->check());
        $entry = $this->entries()[0];
        self::assertNull($entry['http_status']);
        self::assertSame('transport_error', $entry['error']);
        self::assertSame('rejected', $entry['outcome']);
        self::assertStringNotContainsString('private-proxy-password', file_get_contents($this->logFile));
    }

    public function testAllRegisteredEventsHaveDistinctRequestIdsAndExcludeActionSecrets(): void
    {
        $this->enable();
        $this->grant();
        $this->mock($this->response('allow'));
        $original = $_POST;
        $_POST = ['password' => 'private-action-password', 'card_number' => 'private-payment-data'];
        try {
            foreach (array_keys(ActionEvents::EVENTS) as $event) {
                (new Services(new Settings(), new Identity()))->backend()->assertAllowed($event);
            }
        } finally {
            $_POST = $original;
        }
        $entries = $this->entries();
        self::assertSame(array_keys(ActionEvents::EVENTS), array_column($entries, 'event'));
        self::assertCount(6, array_unique(array_column($entries, 'request_id')));
        self::assertCount(1, array_unique(array_column($entries, 'visitor_hash')));
        self::assertStringNotContainsString('private-action-password', file_get_contents($this->logFile));
        self::assertStringNotContainsString('private-payment-data', file_get_contents($this->logFile));
    }

    public function testSharedRequestCacheAndConsumedPaymentDoNotDuplicateLogs(): void
    {
        $this->enable();
        $this->grant();
        $resource = new ResourceRef('woocommerce_cart', 'fixture-debug-cart');
        $this->services->guard()->bindGrant(
            $this->services->identity->context(), $this->services->settings->policy(), $resource
        );
        $this->mock($this->response('allow'));
        $this->services->backend()->assertAllowed('checkout');
        $this->services->backend()->assertAllowed('create_order');
        $this->services->backend()->consumeOrder($resource, 741);
        $this->services->backend()->assertAllowed('payment', 741);
        self::assertSame(1, $this->calls);
        self::assertCount(1, $this->entries());
    }

    public function testUnverifiedDisabledAndAlreadyBlockedRequestsDoNotLog(): void
    {
        $this->enable();
        $this->mock($this->response('block'));
        self::assertInstanceOf(ActionDenied::class, $this->check());
        self::assertSame([], $this->entries());
        $this->grant();
        $this->check();
        $next = new Services(new Settings(), new Identity());
        try {
            $next->backend()->assertAllowed('login');
            self::fail('An existing ban must deny login.');
        } catch (ActionDenied) {
            self::assertCount(1, $this->entries());
            self::assertSame(1, $this->calls);
        }
        $values = $this->services->settings->values();
        $values['keys']['development']['apiKey'] = '';
        update_option(Settings::OPTION, $values);
        $next->backend()->assertAllowed('login');
        self::assertCount(1, $this->entries());
        self::assertSame(1, $this->calls);
    }

    /** @dataProvider staleVerdicts */
    public function testStaleApiResultsLogRejectionInsteadOfClaimingABlock(string $verdict): void
    {
        $this->enable();
        $this->grant();
        $context = $this->services->identity->context();
        $policy = $this->services->settings->policy();
        $state = $this->services->storage()->session($context->scopeId, $context->browserId);
        $this->mock($this->response($verdict), function () use ($context, $policy, $state): void {
            $this->services->guard()->report(
                $context, $policy, SignedPayload::payload('allow', 'changed-visitor'), $state->verificationId
            );
        });
        self::assertInstanceOf(GuardException::class, $this->check());
        $entry = $this->entries()[0];
        self::assertSame($verdict, $entry['api_verdict']);
        self::assertSame('rejected', $entry['outcome']);
        self::assertNotNull($entry['error']);
    }

    public static function staleVerdicts(): array
    {
        return [['allow'], ['block']];
    }

    public function testDebugOnlySettingsSavePreservesGrantResourceAndBanButSecurityChangeRevokes(): void
    {
        $this->grant();
        $context = $this->services->identity->context();
        $storage = $this->services->storage();
        $resource = new ResourceRef('woocommerce_cart', 'fixture-debug-cart');
        $this->services->guard()->bindGrant($context, $this->services->settings->policy(), $resource);
        $storage->run($context->scopeId, static function () use ($storage, $context): void {
            $storage->block($context->scopeId, 'other-visitor', null, time() + 3600);
        });
        $before = $storage->session($context->scopeId, $context->browserId);
        $ban = $storage->blockExpiry($context->scopeId, 'other-visitor', null);
        $values = $this->services->settings->values();
        $this->services->settings->register();
        try {
            foreach ([true, false] as $enabled) {
                $values['debugLogging'] = $enabled;
                update_option(Settings::OPTION, $values);
                self::assertEquals($before, $storage->session($context->scopeId, $context->browserId));
                self::assertNotNull($storage->resource($context->scopeId, $resource));
                self::assertSame($ban, $storage->blockExpiry($context->scopeId, 'other-visitor', null));
            }
            $values['debugLogging'] = true;
            $values['passDuration'] = 60;
            update_option(Settings::OPTION, $values);
            self::assertSame(0, $storage->session($context->scopeId, $context->browserId)->grantedUntil);
            self::assertNull($storage->resource($context->scopeId, $resource));
            self::assertSame($ban, $storage->blockExpiry($context->scopeId, 'other-visitor', null));
        } finally {
            unregister_setting(Settings::OPTION, Settings::OPTION);
        }
    }

    /** @dataProvider staleVerdicts */
    public function testFullLogSinkCannotChangeAllowedOrBlockedDecisions(string $verdict): void
    {
        $this->enable();
        $this->grant();
        $this->mock($this->response($verdict));
        ini_set('error_log', '/dev/full');
        $error = $this->check();
        self::assertSame($verdict === 'allow', $error === null);
        if ($verdict === 'block') {
            self::assertInstanceOf(ActionDenied::class, $error);
            self::assertSame('blocked', $error->decision->status);
        }
    }

    public function testLoggerExceptionsAreContained(): void
    {
        $this->enable();
        $this->mock($this->response('allow'));
        $transport = new WordPressTransport();
        $transport->post('https://dev.hideguard.io/api/v1/verdict', [], '{}');
        $policy = $this->services->settings->policy();
        $throw = static fn() => throw new RuntimeException('fixture debug failure');
        add_filter('salt', $throw);
        try {
            (new VerdictDebugLog($this->services->settings))->record(
                'login', 'fixture-visitor', $policy, $transport, ['verdict' => 'allow'], null
            );
            self::assertSame([], $this->entries());
        } finally {
            remove_filter('salt', $throw);
        }
    }

    private function enable(): void
    {
        $values = $this->services->settings->values();
        $values['debugLogging'] = true;
        update_option(Settings::OPTION, $values);
    }

    private function grant(): void
    {
        $context = $this->services->identity->context();
        $policy = $this->services->settings->policy();
        $attempt = $this->services->guard()->preflight($context, $policy);
        $decision = $this->services->guard()->report($context, $policy, SignedPayload::payload(), $attempt->verificationId);
        self::assertSame('allowed', $decision->status);
    }

    private function mock(mixed $response, ?callable $duringRequest = null): void
    {
        $this->httpMock = function () use ($response, $duringRequest): mixed {
            $this->calls++;
            if ($duringRequest !== null) {
                $duringRequest();
            }
            return $response;
        };
        add_filter('pre_http_request', $this->httpMock, 1);
    }

    private function check(): ?Throwable
    {
        try {
            $this->services->backend()->assertAllowed('checkout');
            return null;
        } catch (ActionDenied|GuardException|ApiException $error) {
            return $error;
        }
    }

    private function response(string $verdict): array
    {
        return self::http(json_encode(['visitorId' => 'fixture-visitor', 'verdict' => $verdict, 'score' => 0, 'reasons' => []], JSON_THROW_ON_ERROR));
    }

    private static function http(string $body, int $status = 200): array
    {
        return ['headers' => [], 'body' => $body, 'response' => ['code' => $status]];
    }

    private function entries(): array
    {
        preg_match_all('/\\[HideGuard debug\\] (.+)/', file_get_contents($this->logFile), $matches);
        return array_map(static fn(string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $matches[1]);
    }
}
