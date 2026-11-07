<?php

declare(strict_types=1);

use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardWordPress\Settings;

final class SettingsTest extends AdapterTestCase
{
    public function testDebugLoggingDefaultsOffAndNormalizesCheckboxOnRepeatedSanitization(): void
    {
        delete_option(Settings::OPTION);
        $settings = new Settings();
        self::assertFalse($settings->debugLogging());
        $input = $settings->values();
        $input['debugLogging'] = '1';
        $values = $settings->sanitize($input);
        self::assertTrue($values['debugLogging']);
        self::assertSame($values, $settings->sanitize($values));
        update_option(Settings::OPTION, $values);
        self::assertTrue($settings->debugLogging());
        ob_start();
        $settings->field(['field' => 'debugLogging']);
        $html = ob_get_clean();
        self::assertStringContainsString('name="hachitech_hideguard[debugLogging]"', $html);
        self::assertStringContainsString('checked=', $html);
        unset($input['debugLogging']);
        $values = $settings->sanitize($input);
        self::assertFalse($values['debugLogging']);
        self::assertSame($values, $settings->sanitize($values));
        update_option(Settings::OPTION, $values);
        self::assertFalse($settings->debugLogging());
    }

    public function testDefaultsMatchMagentoAndLegacyAccessPointsCannotNarrowProtection(): void
    {
        delete_option(Settings::OPTION);
        $settings = new Settings();
        self::assertFalse($settings->policy()->enabled);
        self::assertSame(['all_pages', 'complete_order'], $settings->policy()->accessPoints);
        self::assertSame(3600, $settings->policy()->blockDuration);
        self::assertSame(300, $settings->policy()->passDuration);
        $values = $settings->values();
        $values['points'] = [];
        update_option(Settings::OPTION, $values);
        self::assertSame(Settings::POINTS, $settings->policy()->accessPoints);
    }

    public function testSettingsApiNormalizesProfilesAndCanSanitizeTwice(): void
    {
        $settings = new Settings();
        $input = $settings->values();
        $input['paths'] = "protected\n custom/* ";
        $input['passDuration'] = '0';
        $input['blockDuration'] = '900';
        $input['keys']['production']['endpointUrl'] = 'https://hideguard.example.test:8443/';
        $values = $settings->sanitize($input);
        self::assertSame(['protected', 'custom/*'], $values['paths']);
        self::assertSame(0, $values['passDuration']);
        self::assertSame($values, $settings->sanitize($values));
        self::assertSame(
            'https://hideguard.io',
            $settings->policy(array_replace($values, ['environment' => 'production']))->endpointUrl
        );
        ob_start();
        $settings->field(['field' => 'keys']);
        $html = ob_get_clean();
        self::assertSame(2, substr_count($html, '<fieldset'));
        self::assertSame(1, substr_count($html, 'hidden'));
        self::assertSame(2, substr_count($html, '<textarea'));
        self::assertSame(0, substr_count($html, 'type="url"'));
    }

    /** @dataProvider invalidSettings */
    public function testRejectsInvalidSettingsWithoutReplacingSavedValues(string $field, mixed $value): void
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
            ['environment', 'unknown'], ['environment', 'staging'],
            ['passDuration', -1], ['passDuration', 30], ['blockDuration', 'bad'],
            ['paths', implode("\n", array_fill(0, 51, 'custom/*'))],
        ];
    }

    public function testDevelopmentCannotEnableWithoutSignatureKeyAndLegacyUrlsAreIgnored(): void
    {
        $settings = new Settings();
        $saved = $settings->values();
        $input = $saved;
        $input['keys']['development']['signaturePublicKey'] = '';
        self::assertSame($saved, $settings->sanitize($input));
        $input = $saved;
        $input['keys']['staging']['endpointUrl'] = 'http://hideguard.io/path';
        self::assertSame($saved, $settings->sanitize($input));
        $this->expectException(GuardException::class);
        $settings->policy(array_replace($saved, ['keys' => [
            'development' => ['publicKey' => 'key', 'signaturePublicKey' => 'not-an-ed25519-pem'],
        ]]));
    }

    public function testBlankOrMissingApiKeysPreserveSavedSecretsAcrossProfiles(): void
    {
        $settings = new Settings();
        $saved = $settings->values();
        foreach (['development', 'production'] as $environment) {
            $saved['keys'][$environment]['apiKey'] = 'secret-' . $environment;
        }
        update_option(Settings::OPTION, $saved);
        $input = $saved;
        $input['keys']['development']['apiKey'] = '';
        unset($input['keys']['production']['apiKey']);
        $values = $settings->sanitize($input);
        self::assertSame($saved, $values);
        self::assertSame($values, $settings->sanitize($values));
    }

    public function testApiKeyRemovalRemainsRemovedOnSecondSanitization(): void
    {
        $settings = new Settings();
        $saved = $settings->values();
        $saved['keys']['development']['apiKey'] = 'existing-secret';
        update_option(Settings::OPTION, $saved);
        $input = $saved;
        $input['keys']['development']['apiKey'] = '';
        $input['keys']['development']['removeApiKey'] = '1';
        $values = $settings->sanitize($input);
        self::assertSame('', $values['keys']['development']['apiKey']);
        self::assertSame($values, $settings->sanitize($values));
        update_option(Settings::OPTION, $values);
        self::assertSame('', $settings->apiKey());
        self::assertFalse($settings->backendEnabled());
    }

    public function testNewApiKeyReplacesSavedSecretAndAllowsMaximumLength(): void
    {
        $settings = new Settings();
        $input = $settings->values();
        $input['keys']['development']['apiKey'] = str_repeat('a', 4096);
        $values = $settings->sanitize($input);
        self::assertSame($input['keys']['development']['apiKey'], $values['keys']['development']['apiKey']);
        self::assertSame($values, $settings->sanitize($values));
        update_option(Settings::OPTION, $values);
        $input = $values;
        $input['keys']['development']['apiKey'] = 'replacement-secret';
        self::assertSame('replacement-secret', $settings->sanitize($input)['keys']['development']['apiKey']);
    }

    /** @dataProvider invalidApiKeys */
    public function testInvalidDormantApiKeysDoNotReplaceSavedSettings(mixed $key): void
    {
        $settings = new Settings();
        $saved = $settings->values();
        $input = $saved;
        $input['keys']['production']['apiKey'] = $key;
        self::assertSame($saved, $settings->sanitize($input));
    }

    public static function invalidApiKeys(): array
    {
        return [
            ['invalid key'], ["invalid\nkey"], ["invalid\x7fkey"], [str_repeat('a', 4097)], [['invalid']],
        ];
    }

    public function testBackendActivationFollowsActiveEnvironmentAndGlobalEnable(): void
    {
        $settings = new Settings();
        self::assertSame('', $settings->apiKey());
        self::assertFalse($settings->backendEnabled());
        $values = $settings->values();
        $values['keys']['production']['apiKey'] = 'production-secret';
        update_option(Settings::OPTION, $values);
        self::assertFalse($settings->backendEnabled());
        $values['environment'] = 'production';
        update_option(Settings::OPTION, $values);
        self::assertSame('production-secret', $settings->apiKey());
        self::assertTrue($settings->backendEnabled());
        $values['enabled'] = false;
        update_option(Settings::OPTION, $values);
        self::assertFalse($settings->backendEnabled());
    }

    public function testApiKeyFieldsNeverExposeSavedSecrets(): void
    {
        $settings = new Settings();
        $values = $settings->values();
        $values['keys']['development']['apiKey'] = 'development-private-secret';
        $values['keys']['production']['apiKey'] = 'production-private-secret';
        update_option(Settings::OPTION, $values);
        ob_start();
        $settings->field(['field' => 'keys']);
        $html = ob_get_clean();
        self::assertStringNotContainsString('development-private-secret', $html);
        self::assertStringNotContainsString('production-private-secret', $html);
        self::assertSame(2, substr_count($html, 'type="password" autocomplete="new-password"'));
        self::assertSame(2, substr_count($html, 'value="" placeholder="'));
        self::assertSame(2, substr_count($html, 'Configured; leave blank to keep'));
        self::assertSame(0, substr_count($html, 'Not configured'));
        self::assertSame(2, substr_count($html, '[removeApiKey]'));
    }
}
