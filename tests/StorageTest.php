<?php

declare(strict_types=1);

use Hachitechs\HideguardPhp\Access\AccessContext;
use Hachitechs\HideguardPhp\Access\BrowserState;
use Hachitechs\HideguardPhp\Access\ResourceRef;
use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardPhp\Guard;
use Hachitechs\HideguardPhp\Verification\Ed25519Verifier;
use Hachitechs\HideguardWordPress\Settings;
use Hachitechs\HideguardWordPress\WpdbStorage;

final class StorageTest extends AdapterTestCase
{
    /** @dataProvider revocations */
    public function testRevocationCancelsAttemptsWithoutClearingBlocksOrOtherScopes(string $kind): void
    {
        $storage = WpdbStorage::forSite();
        $resource = new ResourceRef('cart', 'fixture-cart', 'actor');
        $storage->run('scope', function () use ($storage, $resource, $kind): void {
            $state = new BrowserState(
                'browser', actorId: 'actor', visitorId: 'visitor', blockedUntil: time() + 3600,
                grantedUntil: time() + 300, resource: $resource,
                verificationId: str_repeat('a', 32), verificationUntil: time() + 35
            );
            $storage->saveSession('scope', $state);
            $storage->saveSession('other-scope', $state);
            match ($kind) {
                'scope' => $storage->revokeScope('scope'),
                'actor' => $storage->revokeIdentity('scope', null, 'actor'),
                'visitor' => $storage->revokeIdentity('scope', 'visitor', null),
                'resource' => $storage->revokeResource('scope', $resource),
            };
        });
        $state = $storage->session('scope', 'browser');
        self::assertSame(0, $state->grantedUntil);
        self::assertNull($state->resource);
        self::assertNull($state->verificationId);
        self::assertSame(0, $state->verificationUntil);
        self::assertGreaterThan(time(), $state->blockedUntil);
        self::assertSame(str_repeat('a', 32), $storage->session('other-scope', 'browser')->verificationId);
    }

    public static function revocations(): array
    {
        return [['scope'], ['actor'], ['visitor'], ['resource']];
    }

    /** @dataProvider pendingRevocations */
    public function testLateReportsCannotCompleteRevokedPendingAttempts(string $kind): void
    {
        $storage = WpdbStorage::forSite();
        $guard = Guard::factory()->withStorage($storage)->withVerifier(new Ed25519Verifier())->make();
        $policy = (new Settings())->policy();
        $context = new AccessContext('revoked-attempt', 'browser', 'actor');
        $attempt = $guard->preflight($context, $policy);
        if ($kind === 'scope') {
            $guard->revokeScope($context->scopeId);
        } else {
            $storage->run($context->scopeId, fn() => $storage->revokeIdentity($context->scopeId, null, 'actor'));
        }
        self::assertNull($storage->session($context->scopeId, $context->browserId)->verificationId);
        try {
            $guard->report($context, $policy, SignedPayload::payload(), $attempt->verificationId);
            self::fail('A cancelled attempt must reject its late signed response.');
        } catch (GuardException $error) {
            self::assertSame(FailureReason::InvalidVerdict, $error->reason);
        }
        $next = $guard->preflight($context, $policy);
        self::assertNotSame($attempt->verificationId, $next->verificationId);
        self::assertSame('verification_required', $guard->report(
            $context, $policy, SignedPayload::payload(), $attempt->verificationId
        )->status);
        self::assertSame('allowed', $guard->report(
            $context, $policy, SignedPayload::payload(), $next->verificationId
        )->status);
    }

    public static function pendingRevocations(): array
    {
        return [['scope'], ['identity']];
    }

    public function testOrdinaryFailuresRollbackAndSecurityFailuresCommit(): void
    {
        $storage = WpdbStorage::forSite();
        try {
            $storage->run('atomic', function () use ($storage): void {
                $storage->saveSession('atomic', new BrowserState('rollback'));
                throw new RuntimeException('Simulated business failure');
            });
        } catch (RuntimeException) {
            self::assertNull($storage->session('atomic', 'rollback'));
        }
        try {
            $storage->run('atomic', function () use ($storage): void {
                $storage->saveSession('atomic', new BrowserState('denied', blockedUntil: 123));
                throw new GuardException(FailureReason::PermissionDenied, 'Simulated security mutation');
            });
        } catch (GuardException) {
            self::assertSame(123, $storage->session('atomic', 'denied')->blockedUntil);
        }
    }

    public function testResourceBindingSurvivesPersistenceAndRejectsAnotherActor(): void
    {
        $storage = WpdbStorage::forSite();
        $guard = Guard::factory()->withStorage($storage)->withVerifier(new Ed25519Verifier())->make();
        $policy = (new Settings())->policy();
        $context = new AccessContext('resource-test', 'browser-a', '100');
        $attempt = $guard->preflight($context, $policy);
        $guard->report($context, $policy, SignedPayload::payload(), $attempt->verificationId);
        $resource = new ResourceRef('woocommerce_cart', 'server-cart', '100');
        $guard->bindGrant($context, $policy, $resource);
        $guard->assertResourceAllowed('resource-test', $policy, $resource);
        $loaded = WpdbStorage::forSite()->resource('resource-test', $resource);
        self::assertSame('100', $loaded->actorId);
        self::assertSame('server-cart', $loaded->resource->id);
        $this->expectException(GuardException::class);
        $guard->assertResourceAllowed('resource-test', $policy,
            new ResourceRef('woocommerce_cart', 'server-cart', '200'));
    }

    public function testResetBlocksPreservesOtherPassesAndOtherScopes(): void
    {
        $storage = WpdbStorage::forSite();
        $guard = Guard::factory()->withStorage($storage)->withVerifier(new Ed25519Verifier())->make();
        $policy = (new Settings())->policy();
        $a = new AccessContext('site-a', 'blocked');
        $b = new AccessContext('site-a', 'allowed');
        $c = new AccessContext('site-b', 'blocked');
        foreach ([[$a, 'block', 'visitor-a'], [$b, 'allow', 'visitor-b'], [$c, 'block', 'visitor-c']] as $case) {
            [$context, $verdict, $visitor] = $case;
            $attempt = $guard->preflight($context, $policy);
            $guard->report($context, $policy, SignedPayload::payload($verdict, $visitor), $attempt->verificationId);
        }
        $expiry = $guard->inspect($b, $policy)->expiresAt;
        self::assertGreaterThan(0, $guard->resetBlocks('site-a'));
        self::assertSame('allowed', $guard->inspect($b, $policy)->status);
        self::assertSame($expiry, $guard->inspect($b, $policy)->expiresAt);
        self::assertSame('blocked', $guard->inspect($c, $policy)->status);
        self::assertSame(0, $storage->session('site-a', 'blocked')->blockedUntil);
    }
}
