<?php

declare(strict_types=1);

use Hachitechs\HideguardPhp\Access\ResourceRef;
use Hachitechs\HideguardPhp\Exceptions\ApiException;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardWordPress\ActionDenied;
use Hachitechs\HideguardWordPress\ActionEvents;
use Hachitechs\HideguardWordPress\Identity;
use Hachitechs\HideguardWordPress\RestErrors;
use Hachitechs\HideguardWordPress\Services;
use Hachitechs\HideguardWordPress\Settings;
use Hachitechs\HideguardWordPress\WpdbStorage;

final class BackendVerdictsTest extends AdapterTestCase
{
    private Services $services;
    private array $requests = [];
    private ?Closure $httpMock = null;
    private array $originalPost;
    private array $createdUsers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $settings = new Settings();
        $values = $settings->values();
        $values['keys']['apiKey'] = 'fixture-secret';
        $values['keys']['removeApiKey'] = false;
        update_option(Settings::OPTION, $values);
        $this->services = new Services($settings, new Identity());
        $this->originalPost = $_POST;
    }

    protected function tearDown(): void
    {
        if ($this->httpMock !== null) {
            remove_filter('pre_http_request', $this->httpMock, 1);
        }
        $_POST = $this->originalPost;
        wp_set_current_user(0);
        if ($this->createdUsers !== []) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            foreach ($this->createdUsers as $userId) {
                wp_delete_user($userId);
            }
        }
        unset($this->services);
        parent::tearDown();
    }

    public function testAllSixEventsShareOneLookupPerIdentityAndRequest(): void
    {
        self::assertSame(
            ['payment', 'checkout', 'create_order', 'add_to_cart', 'login', 'register'],
            array_keys(ActionEvents::EVENTS)
        );
        $this->grant();
        $this->mockApi($this->response('allow'));
        foreach (array_keys(ActionEvents::EVENTS) as $event) {
            $this->services->backend()->assertAllowed($event);
        }
        self::assertCount(1, $this->requests);
        $nextRequest = new Services(new Settings(), new Identity());
        $nextRequest->backend()->assertAllowed('login');
        self::assertCount(2, $this->requests, 'A later request must fetch a fresh verdict.');
    }

    public function testAllowDoesNotExtendTheSdkGrantOrChangeItsResource(): void
    {
        $this->grant();
        $context = $this->services->identity->context();
        $resource = new ResourceRef('woocommerce_cart', 'trusted-server-cart');
        $this->services->guard()->bindGrant($context, $this->services->settings->policy(), $resource);
        $before = $this->services->storage()->session($context->scopeId, $context->browserId);
        $this->mockApi($this->response('allow'));
        $this->services->backend()->assertAllowed('checkout');
        $after = $this->services->storage()->session($context->scopeId, $context->browserId);
        self::assertEquals($before, $after);
        self::assertSame($before->grantedUntil, $after->grantedUntil);
        $this->services->guard()->assertResourceAllowed($context->scopeId, $this->services->settings->policy(), $resource);
    }

    public function testBlockUsesSharedDecisionRevokesResourcesAndDoesNotExtendOnRetry(): void
    {
        $this->grant();
        $context = $this->services->identity->context();
        $policy = $this->services->settings->policy();
        $resource = new ResourceRef('woocommerce_cart', 'blocked-server-cart');
        $this->services->guard()->bindGrant($context, $policy, $resource);
        $this->mockApi($this->response('block'));
        $error = $this->denied(fn() => $this->services->backend()->assertAllowed('payment'));
        self::assertInstanceOf(ActionDenied::class, $error);
        self::assertSame('blocked', $error->decision->status);
        self::assertSame(401, RestErrors::response($error)->get_status());
        self::assertSame(
            $this->services->guard()->inspect($context, $policy)->toBrowserArray(),
            RestErrors::response($error)->get_data()
        );
        $blocked = $this->services->storage()->session($context->scopeId, $context->browserId);
        self::assertGreaterThan(time(), $blocked->blockedUntil);
        self::assertSame(0, $blocked->grantedUntil);
        self::assertNull($blocked->resource);
        self::assertNull($this->services->storage()->resource($context->scopeId, $resource));
        $nextRequest = new Services(new Settings(), new Identity());
        $retry = $this->denied(fn() => $nextRequest->backend()->assertAllowed('create_order'));
        self::assertInstanceOf(ActionDenied::class, $retry);
        self::assertSame($blocked->blockedUntil, $retry->decision->expiresAt);
        self::assertCount(1, $this->requests, 'An existing ban needs no further API request.');
        $this->expectException(GuardException::class);
        $this->services->guard()->assertResourceAllowed($context->scopeId, $policy, $resource);
    }

    /** @dataProvider invalidResponses */
    public function testApiFailuresDenyTheActionWithoutCreatingATimedBan(mixed $response): void
    {
        $this->grant();
        $context = $this->services->identity->context();
        $this->mockApi($response);
        $error = $this->denied(fn() => $this->services->backend()->assertAllowed('register'));
        self::assertTrue($error instanceof ApiException || $error instanceof GuardException);
        self::assertSame(0, $this->services->storage()->blockExpiry($context->scopeId, 'fixture-visitor', null));
        self::assertSame(0, $this->services->storage()->session($context->scopeId, $context->browserId)->blockedUntil);
        self::assertCount(1, $this->requests);
    }

    public static function invalidResponses(): array
    {
        $valid = ['visitorId' => 'fixture-visitor', 'verdict' => 'allow', 'score' => 0, 'reasons' => []];
        $invalid = static fn(array $changes): array => [
            self::http(json_encode(array_replace($valid, $changes), JSON_THROW_ON_ERROR)),
        ];
        return [
            'invalid JSON' => [self::http('not-json')],
            'JSON scalar' => [self::http('null')],
            'JSON list' => [self::http('[]')],
            'missing visitor' => [self::http('{"verdict":"allow","score":0,"reasons":[]}')],
            'different visitor' => $invalid(['visitorId' => 'other-visitor']),
            'unknown verdict' => $invalid(['verdict' => 'unknown']),
            'different environment' => $invalid(['environment' => 'staging']),
            'unexpected envelope' => [self::http('{"data":{"verdict":"block","visitorId":"fixture-visitor"}}')],
            'missing score' => [self::http('{"visitorId":"fixture-visitor","verdict":"allow","reasons":[]}')],
            'negative score' => $invalid(['score' => -1]),
            'unsafe score' => $invalid(['score' => 9007199254740992]),
            'invalid reasons' => $invalid(['reasons' => 'bad']),
            'non-object reason' => $invalid(['reasons' => ['bad']]),
            'invalid reason code' => $invalid(['reasons' => [['code' => 'bad code']]]),
            'too many reasons' => $invalid(['reasons' => array_fill(0, 65, ['code' => 'fixture'])]),
            'malformed block' => $invalid(['verdict' => 'block', 'score' => -1]),
            'HTTP service failure' => [self::http('{"error":"unavailable"}', 503)],
            'timeout' => [new WP_Error('http_request_failed', 'Transport timeout with private proxy details')],
        ];
    }

    public function testUnverifiedVisitorsCannotRequestBackendVerdicts(): void
    {
        $this->mockApi($this->response('allow'));
        self::assertInstanceOf(ActionDenied::class,
            $this->denied(fn() => $this->services->backend()->assertAllowed('login')));
        $this->grant();
        $context = $this->services->identity->context();
        $storage = $this->services->storage();
        $storage->run($context->scopeId, function () use ($storage, $context): void {
            $state = $storage->session($context->scopeId, $context->browserId);
            $state->verification = 'unverified_test_mode';
            $storage->saveSession($context->scopeId, $state);
        });
        self::assertInstanceOf(ActionDenied::class,
            $this->denied(fn() => $this->services->backend()->assertAllowed('login')));
        self::assertCount(0, $this->requests);
    }

    public function testOnlyVerifiedVisitorAndServerApiKeyReachTheApiOutsideStorageLocks(): void
    {
        $this->grant();
        $_POST['password'] = 'must-never-leave-wordpress';
        $context = $this->services->identity->context();
        $this->mockApi($this->response('allow'), function () use ($context): void {
            $concurrentStorage = WpdbStorage::forSite();
            self::assertTrue($concurrentStorage->run($context->scopeId, static fn(): bool => true));
        });
        $this->services->backend()->assertAllowed('login');
        self::assertCount(1, $this->requests);
        [$url, $args] = $this->requests[0];
        self::assertSame('https://hideguard.io/api/v1/verdict', $url);
        self::assertSame(['visitorId' => 'fixture-visitor'], json_decode($args['body'], true));
        self::assertSame('fixture-secret', $args['headers']['x-api-key']);
        self::assertSame('application/json', $args['headers']['Content-Type']);
        self::assertStringNotContainsString('must-never-leave-wordpress', json_encode([$url, $args]));
    }

    /** @dataProvider staleVerdicts */
    public function testConcurrentVisitorChangeDeniesAStaleResultWithoutBanningNewVisitor(string $verdict): void
    {
        $this->grant();
        $context = $this->services->identity->context();
        $policy = $this->services->settings->policy();
        $state = $this->services->storage()->session($context->scopeId, $context->browserId);
        $this->mockApi($this->response($verdict), function () use ($context, $policy, $state): void {
            $decision = $this->services->guard()->report(
                $context, $policy, SignedPayload::payload('allow', 'new-visitor'), $state->verificationId
            );
            self::assertSame('allowed', $decision->status);
        });
        $this->denied(fn() => $this->services->backend()->assertAllowed('checkout'));
        $after = $this->services->storage()->session($context->scopeId, $context->browserId);
        self::assertSame('new-visitor', $after->visitorId);
        self::assertSame(0, $after->blockedUntil);
        self::assertSame(0, $this->services->storage()->blockExpiry($context->scopeId, 'new-visitor', null));
    }

    public static function staleVerdicts(): array
    {
        return [['allow'], ['block']];
    }

    public function testConcurrentBlockCannotBeClearedByAnApiAllow(): void
    {
        $this->grant();
        $context = $this->services->identity->context();
        $policy = $this->services->settings->policy();
        $state = $this->services->storage()->session($context->scopeId, $context->browserId);
        $this->mockApi($this->response('allow'), function () use ($context, $policy, $state): void {
            $decision = $this->services->guard()->report(
                $context, $policy, SignedPayload::payload('block'), $state->verificationId
            );
            self::assertSame('blocked', $decision->status);
        });
        $error = $this->denied(fn() => $this->services->backend()->assertAllowed('payment'));
        self::assertInstanceOf(ActionDenied::class, $error);
        self::assertSame('blocked', $error->decision->status);
        self::assertSame('blocked', $this->services->guard()->inspect($context, $policy)->status);
    }

    public function testUnknownEventsFailWithoutCallingTheApi(): void
    {
        $this->mockApi($this->response('allow'));
        try {
            $this->services->backend()->assertAllowed('unregistered-action');
            self::fail('An unregistered action must not bypass the event registry.');
        } catch (InvalidArgumentException) {
            self::assertCount(0, $this->requests);
        }
    }

    public function testNativeNewCustomerTransitionPreservesTheVerifiedGrantAndResourceDeadline(): void
    {
        $this->grant();
        $context = $this->services->identity->context();
        $resource = new ResourceRef('woocommerce_cart', 'signup-cart');
        $this->services->guard()->bindGrant($context, $this->services->settings->policy(), $resource);
        $this->mockApi($this->response('allow'));
        $this->services->backend()->assertAllowed('register');
        $before = $this->services->storage()->session($context->scopeId, $context->browserId);
        $userId = $this->newCustomer();
        $events = new ActionEvents($this->services);
        $events->createdCustomer('register', $userId);
        wp_set_current_user($userId);
        $events->authCookie('register', 'native-auth-cookie', time() + 100, time() + 100, $userId);
        $after = $this->services->storage()->session($context->scopeId, $context->browserId);
        $expected = clone $before;
        $expected->actorId = (string)$userId;
        $expected->resource = new ResourceRef($resource->type, $resource->id, (string)$userId);
        self::assertEquals($expected, $after);
        self::assertSame($before->grantedUntil, $after->grantedUntil);
        self::assertSame($before->verificationId, $after->verificationId);
        self::assertSame('allowed', $this->services->guard()->inspect(
            $this->services->identity->context(), $this->services->settings->policy()
        )->status);
        $this->services->backend()->assertAllowed('create_order');
        self::assertCount(1, $this->requests, 'Only native signup can carry this same visitor API approval forward.');
    }

    public function testNativeSignupCanContinueItsOrderPaymentAfterImmediateGrantConsumption(): void
    {
        $this->grant();
        $context = $this->services->identity->context();
        $resource = new ResourceRef('woocommerce_cart', 'native-signup-cart');
        $this->services->guard()->bindGrant($context, $this->services->settings->policy(), $resource);
        $this->mockApi($this->response('allow'));
        $this->services->backend()->assertAllowed('register');
        $userId = $this->newCustomer();
        $this->services->backend()->rememberCreatedCustomer($userId);
        wp_set_current_user($userId);
        $this->services->backend()->adoptCreatedCustomer($userId);
        $this->services->backend()->consumeOrder($resource, 701);
        $this->services->backend()->assertAllowed('payment', 701);
        self::assertCount(1, $this->requests);
        self::assertSame(0, $this->services->storage()->session($context->scopeId, $context->browserId)->grantedUntil);
        $nextRequest = new Services(new Settings(), new Identity());
        $this->denied(fn() => $nextRequest->backend()->assertAllowed('payment', 701));
    }

    /** @dataProvider missingRegistrationProofs */
    public function testCustomerTransitionCannotUseMissingOrFailedRegistrationProof(string $proof): void
    {
        $this->grant();
        $this->mockApi($proof === 'failed' ? new WP_Error('http_request_failed', 'Timeout') : $this->response('allow'));
        if ($proof === 'different-event') {
            $this->services->backend()->assertAllowed('checkout');
        } elseif ($proof === 'failed') {
            $this->denied(fn() => $this->services->backend()->assertAllowed('register'));
        }
        $context = $this->services->identity->context();
        $before = $this->services->storage()->session($context->scopeId, $context->browserId);
        $userId = $this->newCustomer();
        $this->services->backend()->rememberCreatedCustomer($userId);
        wp_set_current_user($userId);
        $this->services->backend()->adoptCreatedCustomer($userId);
        self::assertEquals($before, $this->services->storage()->session($context->scopeId, $context->browserId));
        self::assertInstanceOf(ActionDenied::class,
            $this->denied(fn() => $this->services->backend()->assertAllowed('create_order')));
    }

    public static function missingRegistrationProofs(): array
    {
        return [['none'], ['different-event'], ['failed']];
    }

    /** @dataProvider mismatchedRegistrationActors */
    public function testCustomerTransitionCannotAdoptAnExistingOrMismatchedActor(string $mismatch): void
    {
        $this->grant();
        $this->mockApi($this->response('allow'));
        $this->services->backend()->assertAllowed('register');
        $context = $this->services->identity->context();
        $before = $this->services->storage()->session($context->scopeId, $context->browserId);
        $created = $this->newCustomer();
        $other = $this->newCustomer();
        if ($mismatch !== 'ordinary-login') {
            $this->services->backend()->rememberCreatedCustomer($created);
        }
        wp_set_current_user($other);
        $this->services->backend()->adoptCreatedCustomer($mismatch === 'current-user' ? $created : $other);
        self::assertEquals($before, $this->services->storage()->session($context->scopeId, $context->browserId));
    }

    public static function mismatchedRegistrationActors(): array
    {
        return [['ordinary-login'], ['created-id'], ['current-user']];
    }

    /** @dataProvider changedRegistrationProofs */
    public function testCustomerTransitionRejectsExpiredBlockedOrChangedGuestProof(string $change): void
    {
        $this->grant();
        $this->mockApi($this->response('allow'));
        $this->services->backend()->assertAllowed('register');
        $context = $this->services->identity->context();
        $storage = $this->services->storage();
        $userId = $this->newCustomer();
        $this->services->backend()->rememberCreatedCustomer($userId);
        $storage->run($context->scopeId, function () use ($change, $storage, $context, $userId): void {
            $state = $storage->session($context->scopeId, $context->browserId);
            if ($change === 'expired') {
                $state->grantedUntil = time() - 1;
            } elseif ($change === 'visitor') {
                $state->visitorId = 'changed-visitor';
            } elseif ($change === 'attempt') {
                $state->verificationId = bin2hex(random_bytes(16));
            } elseif ($change === 'blocked') {
                $storage->block($context->scopeId, 'fixture-visitor', null, time() + 3600);
            } else {
                $storage->block($context->scopeId, 'unrelated-visitor', (string)$userId, time() + 3600);
            }
            $storage->saveSession($context->scopeId, $state);
        });
        wp_set_current_user($userId);
        $this->denied(fn() => $this->services->backend()->adoptCreatedCustomer($userId));
        self::assertNull($storage->session($context->scopeId, $context->browserId)->actorId);
        self::assertCount(1, $this->requests);
    }

    public static function changedRegistrationProofs(): array
    {
        return [['expired'], ['blocked'], ['visitor'], ['attempt'], ['target-actor-ban']];
    }

    public function testConsumedGrantOnlyContinuesPaymentForTheSameNativeOrderInThisRequest(): void
    {
        $resource = $this->approvedCart();
        $context = $this->services->identity->context();
        $before = $this->services->storage()->session($context->scopeId, $context->browserId);
        $this->services->backend()->consumeOrder($resource, 701);
        $consumed = $this->services->storage()->session($context->scopeId, $context->browserId);
        self::assertSame(0, $consumed->grantedUntil);
        self::assertNull($consumed->resource);
        self::assertNotNull($before->verificationId);
        self::assertNull($consumed->verificationId);
        self::assertSame(0, $consumed->verificationUntil);
        $this->services->backend()->assertAllowed('payment', 701);
        self::assertCount(1, $this->requests);
        self::assertEquals($consumed, $this->services->storage()->session($context->scopeId, $context->browserId));
        $this->denied(fn() => $this->services->backend()->assertAllowed('payment', 702));
        $this->denied(fn() => $this->services->backend()->assertAllowed('payment'));
        foreach (array_diff(array_keys(ActionEvents::EVENTS), ['payment']) as $event) {
            $this->denied(fn() => $this->services->backend()->assertAllowed($event, 701));
        }
        $nextRequest = new Services(new Settings(), new Identity());
        $this->denied(fn() => $nextRequest->backend()->assertAllowed('payment', 701));
    }

    public function testConsumptionCannotCreatePaymentContinuationWithoutPriorApiApproval(): void
    {
        $this->grant();
        $context = $this->services->identity->context();
        $resource = new ResourceRef('woocommerce_cart', 'unapproved-cart');
        $this->services->guard()->bindGrant($context, $this->services->settings->policy(), $resource);
        $this->mockApi($this->response('allow'));
        $this->services->backend()->consumeOrder($resource, 701);
        $this->denied(fn() => $this->services->backend()->assertAllowed('payment', 701));
        self::assertCount(0, $this->requests);
    }

    public function testConsumedPaymentContinuationCannotOutliveTheOriginalGrant(): void
    {
        $this->grant();
        $context = $this->services->identity->context();
        $storage = $this->services->storage();
        $storage->run($context->scopeId, function () use ($storage, $context): void {
            $state = $storage->session($context->scopeId, $context->browserId);
            $state->grantedUntil = time() + 1;
            $storage->saveSession($context->scopeId, $state);
        });
        $resource = new ResourceRef('woocommerce_cart', 'expiring-cart');
        $this->services->guard()->bindGrant($context, $this->services->settings->policy(), $resource);
        $this->mockApi($this->response('allow'));
        $this->services->backend()->assertAllowed('checkout');
        $this->services->backend()->consumeOrder($resource, 701);
        usleep(1100000);
        $this->denied(fn() => $this->services->backend()->assertAllowed('payment', 701));
        self::assertCount(1, $this->requests);
    }

    public function testConsumedPaymentContinuationCannotBypassAConcurrentBlock(): void
    {
        $resource = $this->approvedCart();
        $context = $this->services->identity->context();
        $this->services->backend()->consumeOrder($resource, 701);
        $this->services->storage()->run($context->scopeId, function () use ($context): void {
            $this->services->storage()->block($context->scopeId, 'fixture-visitor', null, time() + 3600);
        });
        $error = $this->denied(fn() => $this->services->backend()->assertAllowed('payment', 701));
        self::assertInstanceOf(ActionDenied::class, $error);
        self::assertSame('blocked', $error->decision->status);
        self::assertCount(1, $this->requests);
    }

    public function testNewVerificationCancelsTheConsumedPaymentContinuation(): void
    {
        $resource = $this->approvedCart();
        $this->services->backend()->consumeOrder($resource, 701);
        $this->services->guard()->preflight(
            $this->services->identity->context(), $this->services->settings->policy()
        );
        self::assertInstanceOf(ActionDenied::class,
            $this->denied(fn() => $this->services->backend()->assertAllowed('payment', 701)));
        self::assertCount(1, $this->requests);
    }

    /** @dataProvider changedContinuationSettings */
    public function testSettingsChangesCannotReuseConsumedPaymentApproval(string $change): void
    {
        $resource = $this->approvedCart();
        $this->services->backend()->consumeOrder($resource, 701);
        $values = $this->services->settings->values();
        if ($change === 'apiKey') {
            $values['keys']['apiKey'] = 'replacement-fixture-secret';
        } else {
            $values['passDuration'] = 60;
        }
        update_option(Settings::OPTION, $values);
        self::assertInstanceOf(ActionDenied::class,
            $this->denied(fn() => $this->services->backend()->assertAllowed('payment', 701)));
        self::assertCount(1, $this->requests);
    }

    public static function changedContinuationSettings(): array
    {
        return [['apiKey'], ['policy']];
    }

    public function testPrivateChallengeInProductionPreservesTheSdkGrant(): void
    {
        $values = $this->services->settings->values();
        $values['environment'] = 'production';
        $values['keys']['apiKey'] = 'production-fixture-secret';
        update_option(Settings::OPTION, $values);
        $context = $this->services->identity->context();
        $policy = $this->services->settings->policy();
        $attempt = $this->services->guard()->preflight($context, $policy);
        $this->services->guard()->report(
            $context, $policy, SignedPayload::payload('allow', 'fixture-visitor', 'production'), $attempt->verificationId
        );
        $before = $this->services->storage()->session($context->scopeId, $context->browserId);
        $this->mockApi($this->response('challenge'));
        $this->services->backend()->assertAllowed('payment');
        self::assertEquals($before, $this->services->storage()->session($context->scopeId, $context->browserId));
        self::assertCount(1, $this->requests);
    }

    private function approvedCart(): ResourceRef
    {
        $this->grant();
        $context = $this->services->identity->context();
        $resource = new ResourceRef('woocommerce_cart', 'approved-native-cart');
        $this->services->guard()->bindGrant($context, $this->services->settings->policy(), $resource);
        $this->mockApi($this->response('allow'));
        $this->services->backend()->assertAllowed('checkout');
        return $resource;
    }

    private function newCustomer(): int
    {
        $name = 'backend-fixture-' . bin2hex(random_bytes(6));
        $id = wp_insert_user([
            'user_login' => $name, 'user_email' => $name . '@example.test',
            'user_pass' => 'Fixture-private-password-72!', 'role' => 'customer',
        ]);
        self::assertIsInt($id);
        $this->createdUsers[] = $id;
        return $id;
    }

    private function grant(): void
    {
        $context = $this->services->identity->context();
        $policy = $this->services->settings->policy();
        $attempt = $this->services->guard()->preflight($context, $policy);
        $allowed = $this->services->guard()->report($context, $policy, SignedPayload::payload(), $attempt->verificationId);
        self::assertSame('allowed', $allowed->status);
    }

    private function mockApi(mixed $response, ?callable $duringRequest = null): void
    {
        $this->httpMock = function ($preempt, array $args, string $url) use ($response, $duringRequest): mixed {
            $this->requests[] = [$url, $args];
            if ($duringRequest !== null) {
                $duringRequest();
            }
            return $response;
        };
        add_filter('pre_http_request', $this->httpMock, 1, 3);
    }

    private function response(string $verdict): array
    {
        return self::http(json_encode([
            'reasons' => [['code' => 'fixtureVerdict']], 'score' => 100, 'source' => 'visit',
            'verdict' => $verdict, 'visitorId' => 'fixture-visitor',
        ], JSON_THROW_ON_ERROR));
    }

    private static function http(string $body, int $status = 200): array
    {
        return ['headers' => [], 'body' => $body, 'response' => ['code' => $status, 'message' => 'Fixture']];
    }

    private function denied(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (ActionDenied|GuardException|ApiException $error) {
            return $error;
        }
        self::fail('The action must be denied.');
    }
}
