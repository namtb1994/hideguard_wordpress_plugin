<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

use Hachitechs\HideguardPhp\Access\AccessContext;
use Hachitechs\HideguardPhp\Access\BrowserState;
use Hachitechs\HideguardPhp\Access\ResourceRef;
use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardPhp\Guard;
use Hachitechs\HideguardPhp\Verification\PrivateVerdictChecker;

/** Fresh action verdicts use a server-only key and the previously verified visitor identity. */
final class BackendVerdicts
{
    /** One HTTP lookup per identity/request, shared by overlapping native checkout hooks. */
    private array $checked = [];
    private ?BrowserState $registrationProof = null;
    private ?string $registrationIdentity = null;
    private ?int $createdCustomer = null;
    /** @var array{orderId: int, state: BrowserState, identity: string}|null */
    private ?array $paymentContinuation = null;

    public function __construct(private readonly Services $services)
    {
    }

    public function assertAllowed(string $event, ?int $orderId = null): void
    {
        if (!isset(ActionEvents::EVENTS[$event])) {
            throw new \InvalidArgumentException('Unknown HideGuard action event.');
        }
        $settings = $this->services->settings;
        if (!$settings->backendEnabled()) {
            return;
        }
        $policy = $settings->policy();
        $context = $this->services->identity->context();
        $guard = $this->services->guard();
        $current = $guard->inspect($context, $policy);
        $storage = $this->services->storage();
        $state = $storage->session($context->scopeId, $context->browserId);
        if ($current->status !== 'allowed') {
            if (
                $event === 'payment' && $orderId !== null && $current->status !== 'blocked'
                && $this->canContinuePayment($orderId, $context, $policy)
            ) {
                return;
            }
            if (
                $current->status !== 'blocked' && $state !== null
                && $this->services->paypal()->continuePayment($event, $orderId)
            ) {
                return;
            }
            throw new ActionDenied($current);
        }
        if ($state === null || $state->visitorId === null || $state->verification !== 'verified') {
            throw new GuardException(FailureReason::InvalidIdentity, 'A verified browser visitor is required.');
        }
        $identity = $this->identity($context, $state, $policy);
        $paypal = $this->services->paypal();
        $approved = $paypal->approved($event, $state, $orderId);
        $transport = $approved || isset($this->checked[$identity]) ? null : new WordPressTransport();
        $response = null;
        $failure = null;
        try {
            if ($transport !== null) {
                // Never hold a database transaction/lock during a network call.
                $response = $this->services->verdictClient($transport)->getVerdict($state->visitorId);
                $group = $this->group($response, $state->visitorId, $policy->environment);
                if ($group === 'block') {
                    $serverGuard = Guard::factory()->withStorage($storage)
                        ->withVerifier(new ServerBlockVerifier($storage, $state, time()))->make();
                    $blocked = $serverGuard->report($context, $policy, [], $state->verificationId);
                    if ($blocked->status === 'blocked') {
                        throw new ActionDenied($blocked);
                    }
                    // A concurrent verification changed the attempt: do not allow the operation on a stale result.
                    throw new GuardException(
                        FailureReason::PermissionDenied,
                        'Browser verification changed. Retry verification.'
                    );
                }
            }
            // Allow never creates/extends a pass or authorizes a different visitor after an in-flight request.
            $verified = $storage->run($context->scopeId, function () use (
                $storage,
                $state,
                $context,
                $policy,
                $guard
            ): BrowserState {
                $current = $guard->inspect($context, $policy);
                if ($current->status !== 'allowed') {
                    throw new ActionDenied($current);
                }
                $fresh = $storage->session($context->scopeId, $context->browserId);
                if (
                    $fresh === null || $fresh->visitorId !== $state->visitorId
                    || $fresh->actorId !== $state->actorId || $fresh->policyHash !== $state->policyHash
                    || $fresh->verificationId !== $state->verificationId
                ) {
                    throw new GuardException(
                        FailureReason::PermissionDenied,
                        'Browser verification changed. Try again.'
                    );
                }
                return $fresh;
            });
            $paypal->complete();
            $this->checked[$identity] = true;
            if ($event === 'register' && $context->actorId === null) {
                $this->registrationProof = $verified;
                $this->registrationIdentity = $identity;
                $this->createdCustomer = null;
            }
        } catch (\Throwable $error) {
            $failure = $error;
            $paypal->cancel();
            throw $error;
        } finally {
            if ($transport !== null) {
                (new VerdictDebugLog($settings))->record(
                    $event,
                    $state->visitorId,
                    $policy,
                    $transport,
                    $response,
                    $failure
                );
            }
        }
    }

    public function rememberCreatedCustomer(int $customerId): void
    {
        if ($customerId > 0 && $this->registrationProof !== null && get_current_user_id() === 0) {
            $this->createdCustomer ??= $customerId;
        }
    }

    /** Consume the persisted cart grant now; only this request may finish payment for this native order. */
    public function consumeOrder(ResourceRef $resource, int $orderId): void
    {
        $this->paymentContinuation = null;
        $context = $this->services->identity->context();
        $policy = $this->services->settings->policy();
        $guard = $this->services->guard();
        $state = $this->services->storage()->session($context->scopeId, $context->browserId);
        $approved = $orderId > 0 && $state !== null && $state->verification === 'verified'
            && $state->resource?->type === $resource->type && $state->resource?->id === $resource->id
            && $guard->inspect($context, $policy)->status === 'allowed'
            && isset($this->checked[$this->identity($context, $state, $policy)]);
        $paypal = $this->services->paypal();
        $flow = $approved ? $paypal->beforeConsume($orderId) : null;
        $guard->consumeGrant($context->scopeId, $resource);
        $paypal->afterConsume($flow);
        if ($approved) {
            $this->paymentContinuation = [
                'orderId' => $orderId, 'state' => $state, 'identity' => $this->identity($context, $state, $policy),
            ];
        }
    }

    /** Preserve an existing guest grant only for WooCommerce's newly-created, automatically signed-in customer. */
    public function adoptCreatedCustomer(int $customerId): void
    {
        $proof = $this->registrationProof;
        $identity = $this->registrationIdentity;
        $createdCustomer = $this->createdCustomer;
        $this->registrationProof = null;
        $this->registrationIdentity = null;
        $this->createdCustomer = null;
        if ($proof === null || $createdCustomer !== $customerId || get_current_user_id() !== $customerId) {
            return;
        }
        $context = $this->services->identity->context();
        $guest = new AccessContext($context->scopeId, $context->browserId);
        $policy = $this->services->settings->policy();
        $storage = $this->services->storage();
        if (
            $identity === null || !isset($this->checked[$identity])
            || $identity !== $this->identity($guest, $proof, $policy)
        ) {
            throw new GuardException(FailureReason::PermissionDenied, 'Registration verification changed. Try again.');
        }
        $adopted = $storage->run($context->scopeId, function () use (
            $storage,
            $context,
            $guest,
            $policy,
            $proof
        ): BrowserState {
            $decision = $this->services->guard()->inspect($guest, $policy);
            if ($decision->status !== 'allowed') {
                throw new ActionDenied($decision);
            }
            $state = $storage->session($context->scopeId, $context->browserId);
            if (
                $state === null || $state->browserId !== $proof->browserId
                || $state->actorId !== null || $state->actorId !== $proof->actorId
                || $state->visitorId !== $proof->visitorId || $state->policyHash !== $proof->policyHash
                || $state->verificationId !== $proof->verificationId || $state->verification !== 'verified'
                || $state->grantedUntil !== $proof->grantedUntil
            ) {
                throw new GuardException(FailureReason::PermissionDenied, 'Browser verification changed. Try again.');
            }
            if ($storage->blockExpiry($context->scopeId, $state->visitorId, $context->actorId) > time()) {
                throw new GuardException(
                    FailureReason::PermissionDenied,
                    'This customer cannot continue verification.'
                );
            }
            $state->actorId = $context->actorId;
            if ($state->resource !== null) {
                $state->resource = new ResourceRef($state->resource->type, $state->resource->id, $context->actorId);
            }
            $storage->saveSession($context->scopeId, $state);
            return $state;
        });
        // The API verdict concerns this same visitor; only this native signup can carry its approval to the new actor.
        $this->checked[$this->identity($context, $adopted, $policy)] = true;
        $this->services->paypal()->adoptCustomer($adopted);
    }

    private function identity(AccessContext $context, BrowserState $state, ProtectionPolicy $policy): string
    {
        return hash('sha256', json_encode([
            $context->scopeId, $context->browserId, $context->actorId, $state->visitorId,
            $state->verificationId, $policy->fingerprint(), hash('sha256', $this->services->settings->apiKey()),
        ], JSON_THROW_ON_ERROR));
    }

    private function canContinuePayment(int $orderId, AccessContext $context, ProtectionPolicy $policy): bool
    {
        $continuation = $this->paymentContinuation;
        if ($continuation === null || $continuation['orderId'] !== $orderId) {
            return false;
        }
        $proof = $continuation['state'];
        $storage = $this->services->storage();
        return $storage->run($context->scopeId, function () use (
            $storage,
            $context,
            $policy,
            $continuation,
            $proof
        ): bool {
            $state = $storage->session($context->scopeId, $context->browserId);
            return $proof->grantedUntil > time() && $state !== null
                && $state->browserId === $proof->browserId && $state->visitorId === $proof->visitorId
                && $state->actorId === $proof->actorId && $state->actorId === $context->actorId
                && $state->policyHash === $proof->policyHash && $state->policyHash === $policy->fingerprint()
                // Consumption cancels the SDK attempt; only the prior API approval stays in this request.
                && $state->verificationId === null && $state->verificationUntil === 0
                && $state->verification === 'verified'
                && $state->grantedUntil === 0 && $state->resource === null && $state->blockedUntil <= time()
                && isset($this->checked[$continuation['identity']])
                && $continuation['identity'] === $this->identity($context, $proof, $policy)
                && $storage->blockExpiry($context->scopeId, $state->visitorId, $context->actorId) <= time();
        });
    }

    private function group(array $response, string $visitorId, string $environment): string
    {
        if (isset($response['environment']) && $response['environment'] !== $environment) {
            throw new GuardException(FailureReason::InvalidVerdict, 'Invalid HideGuard API verdict response.');
        }
        // Only validate the fresh private API response here; Allow never creates or extends a page grant.
        return (new PrivateVerdictChecker())->check($response, $visitorId)->verdict;
    }
}
