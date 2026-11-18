<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

use Hachitechs\HideguardPhp\Configuration\ProtectionPolicy;
use Hachitechs\HideguardPhp\Exceptions\FailureReason;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardPhp\VerdictClient;
use Hachitechs\HideguardPhp\Verification\Ed25519Verifier;

/** Native Settings API credentials mapped to the shared access policy. */
final class Settings
{
    public const OPTION = 'hachitech_hideguard';
    public const POINTS = ['all_pages', 'complete_order'];
    public const ENDPOINT_URL = 'https://hideguard.io';
    public const DASHBOARD_URL = 'https://hideguard.io/dashboard/';

    public function values(?array $saved = null): array
    {
        $saved ??= get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        $values = array_replace($this->defaults(), $saved);
        $keys = is_array($saved['keys'] ?? null) ? $saved['keys'] : [];
        $fields = $this->defaults()['keys'];
        if ($keys !== [] && array_intersect_key($keys, $fields) === []) {
            // Project the previously selected profile without writing options during a request.
            $profile = $saved['environment'] ?? null;
            $values['legacyProfiles'] = $keys;
            $keys = is_string($profile) && is_array($keys[$profile] ?? null) ? $keys[$profile] : [];
        }
        $values['keys'] = array_replace($fields, array_intersect_key($keys, $fields));
        $values['points'] = self::POINTS;
        unset($values['environment'], $values['endpointUrl']);
        return $values;
    }

    public function policy(?array $values = null): ProtectionPolicy
    {
        $values = $this->values($values);
        $keys = $values['keys'];
        $policy = new ProtectionPolicy(
            enabled: $values['enabled'],
            // Stable package 1.0.2 still requires its signed protocol environment internally.
            environment: 'production',
            publicKey: $keys['publicKey'] ?? '',
            signaturePublicKey: $keys['signaturePublicKey'] ?? '',
            accessPoints: self::POINTS,
            pageRules: $values['paths'],
            blockDuration: $values['blockDuration'],
            passDuration: $values['passDuration'],
            limits: (new RuntimeSettings())->limits(),
            endpointUrl: self::ENDPOINT_URL
        );
        if ($policy->enabled) {
            Ed25519Verifier::validatePublicKey($policy->signaturePublicKey);
        }
        return $policy;
    }

    public function apiKey(): string
    {
        $values = $this->values();
        $policy = $this->policy($values);
        return $this->validateApiKey($values['keys']['apiKey'], $policy->endpointUrl);
    }

    public function backendEnabled(): bool
    {
        return $this->policy()->enabled && $this->apiKey() !== '';
    }

    public function debugLogging(): bool
    {
        return !empty($this->values()['debugLogging']);
    }

    public function register(): void
    {
        register_setting(self::OPTION, self::OPTION, [
            'type' => 'array', 'sanitize_callback' => [$this, 'sanitize'], 'show_in_rest' => false,
        ]);
        add_settings_section('protection', __('Protection', 'hachitechs-hideguard'), '__return_false', self::OPTION);
        $labels = [
            'enabled' => __('Enable', 'hachitechs-hideguard'),
            'keys' => __('API keys', 'hachitechs-hideguard'),
            'paths' => __('Additional protected paths', 'hachitechs-hideguard'),
            'passDuration' => __('HideGuard recheck interval', 'hachitechs-hideguard'),
            'blockDuration' => __('Block duration', 'hachitechs-hideguard'),
            'debugLogging' => __('Debug logging', 'hachitechs-hideguard'),
        ];
        foreach ($labels as $field => $label) {
            add_settings_field($field, $label, [$this, 'field'], self::OPTION, 'protection', ['field' => $field]);
        }
    }

    public function sanitize(mixed $input): array
    {
        try {
            if (!is_array($input)) {
                throw new GuardException(FailureReason::InvalidConfiguration, 'Invalid protection settings.');
            }
            $saved = $this->values();
            $values = $saved;
            $values['enabled'] = !empty($input['enabled']);
            $values['debugLogging'] = !empty($input['debugLogging']);
            $values['points'] = self::POINTS;
            $paths = $input['paths'] ?? '';
            // Settings API can sanitize an already-normalized value a second time.
            if (is_array($paths) && count(array_filter($paths, 'is_string')) === count($paths)) {
                $paths = implode("\n", $paths);
            }
            if (!is_string($paths)) {
                throw new GuardException(FailureReason::InvalidConfiguration, 'Invalid protected paths.');
            }
            $values['paths'] = array_values(array_filter(array_map('trim', preg_split('/\R/', $paths)), 'strlen'));
            foreach (['passDuration', 'blockDuration'] as $field) {
                $values[$field] = filter_var(
                    $input[$field] ?? -1,
                    FILTER_VALIDATE_INT,
                    ['options' => ['default' => -1]]
                );
            }
            foreach (['publicKey', 'signaturePublicKey'] as $key) {
                $value = $input['keys'][$key] ?? $values['keys'][$key];
                $values['keys'][$key] = is_string($value) ? trim($value) : '';
            }
            $key = $input['keys']['apiKey'] ?? '';
            if (!is_string($key)) {
                throw new GuardException(FailureReason::InvalidConfiguration, 'Invalid API key.');
            }
            // Retain removal intent when Settings API sanitizes a value again before saving it.
            $remove = !empty($input['keys']['removeApiKey']);
            $values['keys']['removeApiKey'] = $remove;
            $values['keys']['apiKey'] = $this->validateApiKey(
                $remove ? '' : ($key === '' ? $saved['keys']['apiKey'] : $key),
                self::ENDPOINT_URL
            );
            if ($remove && is_array($values['legacyProfiles'] ?? null)) {
                foreach ($values['legacyProfiles'] as &$profile) {
                    if (is_array($profile)) {
                        $profile['apiKey'] = '';
                    }
                }
                unset($profile);
            }
            $this->policy($values);
            return $values;
        } catch (GuardException $error) {
            add_settings_error(self::OPTION, 'invalid', $error->getMessage());
            return $this->values();
        }
    }

    public function field(array $args): void
    {
        $values = $this->values();
        $field = $args['field'];
        $name = self::OPTION . '[' . $field . ']';
        if ($field === 'enabled') {
            echo '<input type="checkbox" name="' . esc_attr($name) . '" value="1" '
                . checked($values[$field], true, false) . '>';
            echo '<p class="description">'
                . esc_html__('HideGuard verifies Ed25519 signatures on the server.', 'hachitechs-hideguard')
                . '</p>';
        } elseif ($field === 'debugLogging') {
            echo '<label><input type="checkbox" name="' . esc_attr($name) . '" value="1" '
                . checked($values[$field], true, false) . '> '
                . esc_html__('Log backend verdict requests', 'hachitechs-hideguard') . '</label>';
            echo '<p class="description">' . esc_html__(
                'Write request metadata, HTTP status, verdict and timing to the PHP error log. '
                . 'Secrets and raw request/response bodies are excluded. Does not require WP_DEBUG.',
                'hachitechs-hideguard'
            ) . '</p>';
        } elseif ($field === 'paths') {
            echo '<textarea name="' . esc_attr($name) . '" rows="6" class="large-text">'
                . esc_textarea(implode("\n", $values[$field])) . '</textarea>';
            echo '<p class="description">'
                . esc_html__('One path per line; * wildcards supported. Maximum 50 rules.', 'hachitechs-hideguard')
                . '</p>';
        } elseif ($field === 'keys') {
            echo '<fieldset><legend>' . esc_html__('HideGuard API keys', 'hachitechs-hideguard') . '</legend>';
            $labels = [
                'publicKey' => __('Public key (SDK site key)', 'hachitechs-hideguard'),
                'signaturePublicKey' => __('Signature public key (Ed25519 PEM)', 'hachitechs-hideguard'),
                'apiKey' => __('API key (backend secret)', 'hachitechs-hideguard'),
            ];
            foreach ($labels as $key => $label) {
                $keyName = $name . '[' . $key . ']';
                echo '<p><label>' . esc_html($label) . '<br>';
                if ($key === 'signaturePublicKey') {
                    echo '<textarea rows="4" class="large-text code" name="' . esc_attr($keyName) . '">'
                        . esc_textarea($values['keys'][$key]) . '</textarea>';
                } elseif ($key === 'apiKey') {
                    $placeholder = $values['keys']['apiKey'] !== ''
                        ? __('Configured; leave blank to keep', 'hachitechs-hideguard')
                        : __('Not configured', 'hachitechs-hideguard');
                    echo '<input class="regular-text" type="password" autocomplete="new-password" name="'
                        . esc_attr($keyName) . '" value="" placeholder="' . esc_attr($placeholder) . '">';
                } else {
                    echo '<input class="regular-text" type="text" name="' . esc_attr($keyName) . '" value="'
                        . esc_attr($values['keys'][$key]) . '">';
                }
                echo '</label></p>';
                if ($key === 'apiKey') {
                    echo '<p class="description"><a href="' . esc_url(self::DASHBOARD_URL)
                        . '" target="_blank" rel="noopener noreferrer">'
                        . esc_html__('Get your API key in HideGuard Dashboard', 'hachitechs-hideguard') . '</a></p>';
                }
            }
            echo '<p><label><input type="checkbox" name="'
                . esc_attr($name . '[removeApiKey]') . '" value="1"> '
                . esc_html__('Remove the saved API key', 'hachitechs-hideguard') . '</label></p>';
            echo '<p class="description">' . esc_html__(
                'An API key enables backend verdict checks for payment, checkout, order creation, add to cart, '
                . 'login and registration. Leave it unconfigured to keep page verification only.',
                'hachitechs-hideguard'
            ) . '</p>';
            echo '<p class="description">' . esc_html__(
                'SDK and backend API use https://hideguard.io. All public pages and order completion are protected.',
                'hachitechs-hideguard'
            ) . '</p></fieldset>';
        } else {
            $options = match ($field) {
                'passDuration' => array_combine(ProtectionPolicy::PASS_DURATIONS, [
                    __('Always', 'hachitechs-hideguard'), __('1 minute', 'hachitechs-hideguard'),
                    __('5 minutes', 'hachitechs-hideguard'), __('15 minutes', 'hachitechs-hideguard'),
                    __('1 hour', 'hachitechs-hideguard'),
                ]),
                'blockDuration' => array_combine(ProtectionPolicy::BLOCK_DURATIONS, [
                    __('15 minutes', 'hachitechs-hideguard'), __('1 hour', 'hachitechs-hideguard'),
                    __('6 hours', 'hachitechs-hideguard'), __('24 hours', 'hachitechs-hideguard'),
                ]),
            };
            echo '<select name="' . esc_attr($name) . '" data-hachitech-field="' . esc_attr($field) . '">';
            foreach ($options as $value => $label) {
                echo '<option value="' . esc_attr((string)$value) . '" ' . selected($values[$field], $value, false)
                    . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
        }
    }

    private function defaults(): array
    {
        return [
            'enabled' => false, 'points' => ['all_pages', 'complete_order'],
            'debugLogging' => false,
            'paths' => [], 'passDuration' => ProtectionPolicy::DEFAULT_PASS_DURATION, 'blockDuration' => 3600,
            'keys' => [
                'publicKey' => '', 'signaturePublicKey' => '',
                'apiKey' => '', 'removeApiKey' => false,
            ],
        ];
    }

    private function validateApiKey(mixed $key, string $endpointUrl): string
    {
        if (!is_string($key) || strlen($key) > 4096) {
            throw new GuardException(FailureReason::InvalidConfiguration, 'Invalid API key.');
        }
        if ($key !== '') {
            new VerdictClient($endpointUrl, $key);
        }
        return $key;
    }
}
