<?php

declare(strict_types=1);

use Hachitechs\HideguardWordPress\Settings;
use PHPUnit\Framework\TestCase;

abstract class AdapterTestCase extends TestCase
{
    protected function setUp(): void
    {
        global $wpdb;
        wp_set_current_user(0);
        delete_option('hg_fixture_api_response');
        delete_option('hg_fixture_api_calls');
        delete_option('hg_fixture_paypal_enabled');
        delete_option('hg_fixture_paypal_calls');
        delete_option('hg_fixture_request_gate');
        foreach (['session', 'block', 'rate', 'control', 'circuit'] as $table) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'hachitech_' . $table);
        }
        $settings = new Settings();
        $values = $settings->values();
        $values['enabled'] = true;
        $values['debugLogging'] = false;
        $values['points'] = [];
        $values['paths'] = ['protected'];
        $values['passDuration'] = 300;
        $values['blockDuration'] = 3600;
        $values['keys'] = [
            'publicKey' => 'fixture-site',
            'signaturePublicKey' => SignedPayload::pem(),
            'apiKey' => '',
            'removeApiKey' => false,
        ];
        update_option(Settings::OPTION, $values);
    }
    protected function tearDown(): void
    {
        // The suite models many HTTP requests in one process; release completed request graphs.
        gc_collect_cycles();
        parent::tearDown();
    }
}
