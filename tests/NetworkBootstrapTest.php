<?php

declare(strict_types=1);

use Hachitechs\HideguardWordPress\Settings;

final class NetworkBootstrapTest extends HttpTestCase
{
    public function testBootstrapPrecedesEarlyWidgetsAndJqueryBridgePrecedesExistingInlineInitializers(): void
    {
        update_option('hg_fixture_request_gate', true);
        [$status, $html] = $this->request('/protected/');
        self::assertSame(200, $status);
        $bootstrap = strpos($html, 'id="hachitechs-hideguard-request-gate"');
        $widget = strpos($html, 'id="hg-fixture-early-widget"');
        $jquery = strpos($html, 'id="jquery-core-js"');
        $bridge = strpos($html, 'window.HachitechsHideguardNetwork?.getGate()?.attachJQuery(window.jQuery);');
        $initializer = strpos($html, 'window.hgFixture.jquery = jQuery.ajax');
        foreach ([$bootstrap, $widget, $jquery, $bridge, $initializer] as $position) {
            self::assertNotFalse($position);
        }
        self::assertLessThan($widget, $bootstrap);
        self::assertLessThan($jquery, $bootstrap);
        self::assertLessThan($bridge, $jquery);
        self::assertLessThan($initializer, $bridge);
        self::assertSame(1, substr_count($html, 'window.HachitechsHideguardNetwork?.getGate()?.attachJQuery(window.jQuery);'));
    }

    public function testAllPublicPagesInstallTheQueueAndDisabledPagesDoNot(): void
    {
        [, $html] = $this->request('/public/');
        self::assertStringContainsString('id="hachitechs-hideguard-request-gate"', $html);
        $values = (new Settings())->values();
        $values['enabled'] = false;
        update_option(Settings::OPTION, $values);
        [, $html] = $this->request('/protected/');
        self::assertStringNotContainsString('id="hachitechs-hideguard-request-gate"', $html);
        self::assertDoesNotMatchRegularExpression('/<body[^>]*\\binert\\b/', $html);
    }

    public function testNativeLoginPageAlsoReceivesTheEarlyQueue(): void
    {
        $values = (new Settings())->values();
        $values['keys']['apiKey'] = 'fixture-backend-key';
        update_option(Settings::OPTION, $values);
        [$status, $html] = $this->request('/wp-login.php');
        self::assertSame(200, $status);
        self::assertStringContainsString('id="hachitechs-hideguard-request-gate"', $html);
        self::assertStringContainsString('wp-script-module-data-hachitech-hideguard', $html);
        self::assertLessThan(strpos($html, 'id="loginform"'), strpos($html, 'id="hachitechs-hideguard-request-gate"'));
    }
}
