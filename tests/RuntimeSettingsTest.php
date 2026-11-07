<?php

declare(strict_types=1);

use Hachitechs\HideguardPhp\Configuration\RateLimits;
use Hachitechs\HideguardPhp\Exceptions\GuardException;
use Hachitechs\HideguardWordPress\RuntimeSettings;
use Hachitechs\HideguardWordPress\Settings;

final class RuntimeSettingsTest extends AdapterTestCase
{
    public function testDefaultsAndPartialDeploymentOverride(): void
    {
        self::assertEquals(new RateLimits(), RuntimeSettings::resolveLimits([]));
        $limits = RuntimeSettings::resolveLimits(['scope_requests' => ' 001200 ']);
        self::assertSame(1200, $limits->scopeRequests);
        self::assertSame(60, $limits->identityRequests);
        self::assertSame(60, $limits->windowSeconds);
        $circuit = (new RuntimeSettings())->circuit();
        self::assertSame(5, $circuit->threshold);
        self::assertSame(60, $circuit->windowSeconds);
        self::assertSame(30, $circuit->openSeconds);
        self::assertGreaterThan(10, $circuit->probeLeaseSeconds);
    }

    /** @dataProvider invalidLimits */
    public function testInvalidDeploymentConfigurationFailsClosed(mixed $limits): void
    {
        $this->expectException(GuardException::class);
        RuntimeSettings::resolveLimits($limits);
    }

    public static function invalidLimits(): array
    {
        return array_map(static fn($value) => [$value], [
            null, false, 2, ['unknown' => 10], ['window_seconds' => 1], ['scope_requests' => 0],
            ['identity_requests' => -1], ['session_capacity' => true], ['session_capacity' => 1.5],
            ['session_capacity' => '1e3'], ['session_capacity' => '2147483648'],
            ['session_retention_seconds' => null], ['scope_requests' => []],
        ]);
    }

    public function testLegacyAndInjectedSettingsCannotChangeEffectivePolicy(): void
    {
        $settings = new Settings();
        $values = $settings->values();
        $before = $settings->policy($values);
        $values['points'] = ['cart'];
        $values['keys']['development']['endpointUrl'] = 'https://untrusted.example';
        $values['limits'] = ['scope_requests' => 999999];
        $values['circuit'] = ['enabled' => false];
        $policy = $settings->policy($values);
        self::assertSame($before->fingerprint(), $policy->fingerprint());
        self::assertSame(Settings::POINTS, $policy->accessPoints);
        self::assertSame('https://dev.hideguard.io', $policy->endpointUrl);
        self::assertEquals(new RateLimits(), $policy->limits);
        $sanitized = $settings->sanitize($values);
        self::assertSame($before->fingerprint(), $settings->policy($sanitized)->fingerprint());
    }

    public function testLegacyStagingRequiresExplicitMigrationAndKeepsItsCredentials(): void
    {
        $settings = new Settings();
        $values = $settings->values();
        $values['environment'] = 'staging';
        $values['keys']['staging'] = ['apiKey' => 'legacy-secret'];
        update_option(Settings::OPTION, $values);
        try {
            $settings->policy();
            self::fail('Legacy staging must not silently switch credentials or host.');
        } catch (GuardException) {
            self::assertSame('legacy-secret', get_option(Settings::OPTION)['keys']['staging']['apiKey']);
        }
        $values['environment'] = 'development';
        $values = $settings->sanitize($values);
        self::assertSame('legacy-secret', $values['keys']['staging']['apiKey']);
        self::assertSame('development', $values['environment']);
        ob_start();
        $settings->field(['field' => 'environment']);
        $html = ob_get_clean();
        self::assertStringNotContainsString('value="staging"', $html);
        self::assertSame(2, substr_count($html, '<option'));
    }
}
