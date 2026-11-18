<?php

declare(strict_types=1);

use Hachitechs\HideguardPhp\Access\AccessContext;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardPhp\Guard;
use Hachitechs\HideguardPhp\Verification\Ed25519Verifier;
use Hachitechs\HideguardWordPress\Settings;
use Hachitechs\HideguardWordPress\WpdbStorage;

final class SettingsTest extends AdapterTestCase
{
    public function testDefaultsUseOneLiveProfileAndCannotNarrowAccessPoints(): void
    {
        delete_option(Settings::OPTION);
        $settings = new Settings();
        self::assertFalse($settings->policy()->enabled);
        self::assertFalse($settings->debugLogging());
        self::assertSame(Settings::POINTS, $settings->policy()->accessPoints);
        self::assertSame('https://hideguard.io', $settings->policy()->endpointUrl);
        self::assertSame(3600, $settings->policy()->blockDuration);
        self::assertSame(300, $settings->policy()->passDuration);
        self::assertArrayNotHasKey('environment', $settings->values());
        $values = $settings->values();
        $values['points'] = [];
        self::assertSame(Settings::POINTS, $settings->policy($values)->accessPoints);
    }

    public function testSanitizeNormalizesPathsAndIsIdempotent(): void
    {
        $settings = new Settings();
        $input = $settings->values();
        $input['paths'] = "protected\n custom/* ";
        $input['passDuration'] = '0';
        $input['blockDuration'] = '900';
        $values = $settings->sanitize($input);
        self::assertSame(['protected', 'custom/*'], $values['paths']);
        self::assertSame(0, $values['passDuration']);
        self::assertSame(900, $values['blockDuration']);
        self::assertSame($values, $settings->sanitize($values));
    }

    /** @dataProvider invalidSettings */
    public function testInvalidSettingsDoNotReplaceSavedValues(string $field, mixed $value): void
    {
        $settings = new Settings();
        $saved = $settings->values();
        $input = $saved;
        $input[$field] = $value;
        self::assertSame($saved, $settings->sanitize($input));
    }

    public static function invalidSettings(): array
    {
        return [
            ['passDuration', -1], ['passDuration', 30], ['blockDuration', 'bad'],
            ['paths', implode("\n", array_fill(0, 51, 'custom/*'))], ['paths', [['invalid']]],
        ];
    }

    public function testEnabledProtectionRequiresSiteAndSignatureKeys(): void
    {
        $settings = new Settings();
        $saved = $settings->values();
        foreach (['publicKey', 'signaturePublicKey'] as $key) {
            $input = $saved;
            $input['keys'][$key] = '';
            self::assertSame($saved, $settings->sanitize($input));
        }
        $input = $saved;
        $input['keys']['signaturePublicKey'] = 'invalid-pem';
        $this->expectException(GuardException::class);
        $settings->policy($input);
    }

    public function testBlankAndMissingApiKeyRetainSecretAndRemovalIsIdempotent(): void
    {
        $settings = new Settings();
        $saved = $settings->values();
        $saved['keys']['apiKey'] = 'saved-secret';
        update_option(Settings::OPTION, $saved);
        foreach ([true, false] as $missing) {
            $input = $saved;
            $input['keys']['apiKey'] = '';
            if ($missing) {
                unset($input['keys']['apiKey']);
            }
            self::assertSame($saved, $settings->sanitize($input));
        }
        $input['keys']['removeApiKey'] = '1';
        $removed = $settings->sanitize($input);
        self::assertSame('', $removed['keys']['apiKey']);
        self::assertSame($removed, $settings->sanitize($removed));
        update_option(Settings::OPTION, $removed);
        self::assertSame('', $settings->apiKey());
        self::assertFalse($settings->backendEnabled());
    }

    public function testNewApiKeyAllowsMaximumLengthAndCanBeReplaced(): void
    {
        $settings = new Settings();
        $input = $settings->values();
        $input['keys']['apiKey'] = str_repeat('a', 4096);
        $values = $settings->sanitize($input);
        self::assertSame($input['keys']['apiKey'], $values['keys']['apiKey']);
        self::assertSame($values, $settings->sanitize($values));
        update_option(Settings::OPTION, $values);
        $input['keys']['apiKey'] = 'replacement-secret';
        self::assertSame('replacement-secret', $settings->sanitize($input)['keys']['apiKey']);
    }

    /** @dataProvider invalidApiKeys */
    public function testInvalidApiKeysDoNotReplaceSavedSettings(mixed $key): void
    {
        $settings = new Settings();
        $saved = $settings->values();
        $input = $saved;
        $input['keys']['apiKey'] = $key;
        self::assertSame($saved, $settings->sanitize($input));
    }

    public static function invalidApiKeys(): array
    {
        return [['invalid key'], ["invalid\nkey"], ["invalid\x7fkey"], [str_repeat('a', 4097)], [['invalid']]];
    }

    public function testBackendActivationFollowsTheOnlyApiKeyAndGlobalEnable(): void
    {
        $settings = new Settings();
        self::assertFalse($settings->backendEnabled());
        $values = $settings->values();
        $values['keys']['apiKey'] = 'live-secret';
        update_option(Settings::OPTION, $values);
        self::assertTrue($settings->backendEnabled());
        self::assertSame('live-secret', $settings->apiKey());
        $values['enabled'] = false;
        update_option(Settings::OPTION, $values);
        self::assertFalse($settings->backendEnabled());
    }

    public function testSettingsHaveNoEnvironmentSelectorAndLinkToDashboardWithoutExposingSecret(): void
    {
        $settings = new Settings();
        $values = $settings->values();
        $values['keys']['apiKey'] = 'private-DO-NOT-RENDER';
        update_option(Settings::OPTION, $values);
        $settings->register();
        remove_filter('sanitize_option_' . Settings::OPTION, [$settings, 'sanitize']);
        global $wp_settings_fields;
        self::assertArrayNotHasKey('environment', $wp_settings_fields[Settings::OPTION]['protection']);
        ob_start();
        $settings->field(['field' => 'keys']);
        $html = ob_get_clean();
        self::assertStringNotContainsString('private-DO-NOT-RENDER', $html);
        self::assertSame(1, substr_count($html, '<fieldset'));
        self::assertSame(1, substr_count($html, 'type="password"'));
        self::assertSame(1, substr_count($html, '[removeApiKey]'));
        self::assertStringNotContainsString('data-hachitech-environment', $html);
        self::assertStringContainsString('href="https://hideguard.io/dashboard/"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
        self::assertStringContainsString('value="" placeholder="', $html);
    }

    /** @dataProvider legacyProfiles */
    public function testLegacyActiveKeysArePreservedWithoutWritingDuringRead(string $selected): void
    {
        $settings = new Settings();
        $saved = $settings->values();
        $saved['environment'] = $selected;
        $saved['keys'] = [];
        foreach (['development', 'staging', 'production'] as $profile) {
            $saved['keys'][$profile] = [
                'publicKey' => 'site-' . $profile,
                'signaturePublicKey' => SignedPayload::pem(),
                'apiKey' => 'secret-' . $profile,
            ];
        }
        update_option(Settings::OPTION, $saved);
        $values = $settings->values();
        self::assertSame($saved, get_option(Settings::OPTION));
        self::assertArrayNotHasKey('environment', $values);
        self::assertSame('site-' . $selected, $values['keys']['publicKey']);
        self::assertSame('secret-' . $selected, $settings->apiKey());
        self::assertSame($saved['keys'], $values['legacyProfiles']);
        ob_start();
        $settings->field(['field' => 'keys']);
        $html = ob_get_clean();
        foreach (['development', 'staging', 'production'] as $profile) {
            self::assertStringNotContainsString('secret-' . $profile, $html);
        }
        self::assertSame('https://hideguard.io', $settings->policy()->endpointUrl);
        $input = $values;
        $input['keys']['apiKey'] = '';
        $values = $settings->sanitize($input);
        update_option(Settings::OPTION, $values);
        self::assertSame('secret-' . $selected, $settings->apiKey());
        self::assertSame($saved['keys'], $settings->values()['legacyProfiles']);
        self::assertSame($values, $settings->sanitize($values));
    }

    public static function legacyProfiles(): array
    {
        return [['development'], ['staging'], ['production']];
    }

    public function testRemoveAlsoClearsApiSecretsFromLegacyRecoveryMetadata(): void
    {
        $settings = new Settings();
        $saved = $settings->values();
        $saved['keys']['apiKey'] = 'current-secret';
        $saved['legacyProfiles'] = [
            'development' => ['apiKey' => 'old-secret', 'publicKey' => 'public-site'],
            'production' => ['apiKey' => 'current-secret'],
        ];
        update_option(Settings::OPTION, $saved);
        $input = $settings->values();
        $input['keys']['apiKey'] = '';
        $input['keys']['removeApiKey'] = true;
        $removed = $settings->sanitize($input);
        self::assertSame('', $removed['keys']['apiKey']);
        foreach ($removed['legacyProfiles'] as $profile) {
            self::assertSame('', $profile['apiKey']);
        }
        self::assertSame('public-site', $removed['legacyProfiles']['development']['publicKey']);
        self::assertSame($removed, $settings->sanitize($removed));
    }

    public function testMissingLegacyActiveProfileDoesNotSelectAnotherProfilesSecrets(): void
    {
        $settings = new Settings();
        $saved = $settings->values();
        $saved['environment'] = 'unknown';
        $saved['keys'] = ['production' => $saved['keys']];
        update_option(Settings::OPTION, $saved);
        self::assertSame('', $settings->values()['keys']['publicKey']);
        self::assertSame($saved, get_option(Settings::OPTION));
        $this->expectException(GuardException::class);
        $settings->policy();
    }

    public function testPostedEnvironmentAndProfilesCannotReplaceFlatKeysOrEndpoint(): void
    {
        $settings = new Settings();
        $saved = $settings->values();
        $input = $saved;
        $input['environment'] = 'staging';
        $input['keys']['staging'] = ['apiKey' => 'injected'];
        $input['keys']['endpointUrl'] = 'https://invalid.example';
        $input['endpointUrl'] = 'https://invalid.example';
        self::assertSame($saved, $settings->sanitize($input));
        self::assertSame($settings->policy()->fingerprint(), $settings->policy($input)->fingerprint());
    }

    public function testDebugOnlyMigrationSaveKeepsAnExistingGrant(): void
    {
        $settings = new Settings();
        $saved = $settings->values();
        $saved['environment'] = 'production';
        $saved['keys'] = ['production' => $saved['keys']];
        update_option(Settings::OPTION, $saved);
        $guard = Guard::factory()->withStorage(WpdbStorage::forSite())->withVerifier(new Ed25519Verifier())->make();
        $context = new AccessContext((string)get_current_blog_id(), 'settings-migration');
        $policy = $settings->policy();
        $attempt = $guard->preflight($context, $policy);
        $guard->report($context, $policy, SignedPayload::payload(), $attempt->verificationId);
        $input = $settings->values();
        $input['debugLogging'] = true;
        update_option(Settings::OPTION, $settings->sanitize($input));
        self::assertSame('allowed', $guard->inspect($context, $policy)->status);
    }
}
