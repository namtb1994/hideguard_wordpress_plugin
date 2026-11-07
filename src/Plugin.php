<?php

declare(strict_types=1);

namespace Hachitechs\HideguardWordPress;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** WordPress lifecycle, HTTP endpoints and UI around the shared Guard. */
final class Plugin
{
    private readonly Services $services;
    private readonly Commerce $commerce;
    private bool $protected = false;
    private bool $loadRuntime = false;
    private string $initialState = 'pending';
    private bool $gate = false;
    private string $returnUrl = '';
    private ?string $verificationId = null;

    public function __construct(private readonly string $file)
    {
        $this->services = new Services(new Settings(), new Identity());
        $this->commerce = new Commerce($this->services);
    }

    public function boot(): void
    {
        add_action('admin_init', [$this->services->settings, 'register']);
        add_action('admin_menu', function (): void {
            add_options_page(
                'Hachitech Hideguard',
                'Hachitech Hideguard',
                'manage_options',
                Settings::OPTION,
                [$this, 'settingsPage']
            );
        });
        add_filter('plugin_action_links_' . plugin_basename($this->file), static function (array $links): array {
            array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=' . Settings::OPTION))
                . '">' . esc_html__('Settings', 'hachitechs-hideguard') . '</a>');
            return $links;
        });
        add_action('admin_enqueue_scripts', function (string $hook): void {
            if ($hook === 'settings_page_' . Settings::OPTION) {
                wp_enqueue_script(
                    'hachitech-hideguard-admin',
                    plugins_url('assets/admin.js', $this->file),
                    [],
                    '1.5.0',
                    true
                );
            }
        });
        add_action('template_redirect', [$this, 'protectRequest'], -100);
        add_action('wp_head', [$this, 'networkBootstrap'], PHP_INT_MIN);
        add_action('login_head', [$this, 'networkBootstrap'], PHP_INT_MIN);
        add_action('wp_print_scripts', [$this, 'prioritizeJqueryGate'], PHP_INT_MAX);
        add_action('wp_print_footer_scripts', [$this, 'prioritizeJqueryGate'], PHP_INT_MIN);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('login_init', [$this, 'prepareLogin']);
        add_action('login_enqueue_scripts', [$this, 'enqueue']);
        add_action('login_footer', [$this, 'loginModules']);
        add_filter('script_module_data_hachitech-hideguard', [$this, 'browserConfig']);
        add_action('rest_api_init', [$this, 'routes']);
        add_filter('rest_post_dispatch', [$this, 'privateResponse'], 10, 3);
        add_action('admin_post_hachitech_hideguard_reset', [$this, 'reset']);
        add_action('update_option_' . Settings::OPTION, function (mixed $old, mixed $new): void {
            if (is_array($old) && is_array($new)) {
                foreach ([$old, $new] as $index => $values) {
                    unset($values['debugLogging']);
                    // Removal intent is a Settings API form detail; the saved key itself controls protection.
                    foreach (array_keys($values['keys'] ?? []) as $environment) {
                        unset($values['keys'][$environment]['removeApiKey']);
                    }
                    if ($index === 0) {
                        $old = $values;
                    } else {
                        $new = $values;
                    }
                }
            }
            if ($old !== $new) {
                $this->services->guard()->revokeScope((string)get_current_blog_id());
            }
        }, 10, 2);
        add_action('hachitech_hideguard_cleanup', function (): void {
            $this->services->guard()->cleanup((string)get_current_blog_id(), $this->services->settings->policy());
        });
        add_action('plugins_loaded', [$this, 'upgradeStorage'], -100);
        add_action('plugins_loaded', function (): void {
            if (!empty($this->services->settings->values()['enabled'])) {
                if (!defined('DONOTCACHEPAGE')) {
                    define('DONOTCACHEPAGE', true);
                }
                // WP Super Cache's late cache creation must not publish browser-specific HTML.
                add_filter('do_createsupercache', '__return_false');
            }
            do_action('hachitech_hideguard_ready', $this->services);
        });
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('hideguard upgrade', function (): void {
                $this->activate();
                \WP_CLI::success('HideGuard schema upgraded and session counters reconciled.');
            });
            \WP_CLI::add_command('hideguard sessions reconcile', function (): void {
                $storage = $this->services->storage();
                $scope = (string)get_current_blog_id();
                $count = $storage->run($scope, fn() => $storage->reconcileSessionCount($scope));
                \WP_CLI::success('HideGuard sessions: ' . $count);
            });
        }
        $this->commerce->boot();
        (new ActionEvents($this->services))->boot();
        $this->services->paypal()->boot();
    }

    public function activate(bool $networkWide = false): void
    {
        if ($networkWide || is_multisite()) {
            wp_die(esc_html__('This plugin supports single-site WordPress installations.', 'hachitechs-hideguard'));
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            wp_die(esc_html__('Enable PHP sodium before activating HideGuard.', 'hachitechs-hideguard'));
        }
        WpdbStorage::forSite()->initializeSchema();
        update_option('hachitechs_hideguard_schema', WpdbStorage::SCHEMA_VERSION, false);
        if (!wp_next_scheduled('hachitech_hideguard_cleanup')) {
            wp_schedule_event(time(), 'hourly', 'hachitech_hideguard_cleanup');
        }
    }

    public function upgradeStorage(): void
    {
        if ((int)get_option('hachitechs_hideguard_schema', 0) < WpdbStorage::SCHEMA_VERSION) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>' . esc_html__(
                    'HideGuard storage upgrade required. Drain old workers and reactivate the plugin or run '
                    . 'wp hideguard upgrade before reopening traffic.',
                    'hachitechs-hideguard'
                ) . '</p></div>';
            });
            if (!defined('WP_CLI') || !WP_CLI) {
                add_action('parse_request', static function (): void {
                    if (!empty(get_option(Settings::OPTION, [])['enabled'])) {
                        nocache_headers();
                        wp_die('HideGuard storage upgrade required.', '', ['response' => 503]);
                    }
                }, PHP_INT_MIN);
            }
        }
    }

    public function deactivate(): void
    {
        wp_clear_scheduled_hook('hachitech_hideguard_cleanup');
        // Retain settings and access state across deactivation.
    }

    public function protectRequest(): void
    {
        try {
            $policy = $this->services->settings->policy();
            $this->gate = $policy->enabled && isset($_GET['hachitech_hideguard_gate']);
            $this->protected = $this->gate || (new PageRules())->protects($policy);
            $this->loadRuntime = $this->protected || $this->commerce->enabled()
                || $this->services->settings->backendEnabled();
            $return = $this->gate ? ($_GET['return'] ?? '') : ($_SERVER['REQUEST_URI'] ?? '/');
            $this->returnUrl = $this->safeReturn(is_string($return) ? wp_unslash($return) : '');
            if (!$this->loadRuntime) {
                return;
            }
            $this->privateHeaders();
            // Establish browser identity before any template output, including action-only pages.
            $context = $this->services->identity->context();
            if (!$this->protected) {
                return;
            }
            $guard = $this->services->guard();
            $decision = !$this->gate && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
                ? $guard->preflight($context, $policy) : $guard->inspect($context, $policy);
            $this->verificationId = $decision->verificationId;
            if ($decision->status === 'blocked') {
                status_header(401);
                header('Retry-After: ' . max(1, $decision->retryAfterSeconds));
                $this->initialState = 'blocked';
                if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
                    $this->renderGate();
                }
                wp_send_json($decision->toBrowserArray(), 401);
            }
            if (
                $decision->status === 'verification_failed' && !$this->gate
                && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
            ) {
                wp_safe_redirect($this->browserConfig()['deniedUrl']);
                exit;
            }
            if ($this->gate) {
                if ($decision->status === 'allowed' && $policy->passDuration > 0) {
                    wp_safe_redirect($this->returnUrl);
                    exit;
                }
                $this->initialState = $decision->status === 'verification_failed'
                    || isset($_GET['failed']) ? 'error' : 'pending';
                status_header($this->initialState === 'error' ? 403 : 200);
                $this->renderGate();
            }
            if ($decision->status !== 'allowed' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
                wp_send_json(['error' => 'Access verification is required.'], 403);
            }
            ob_start(static function (string $html): string {
                $tags = new \WP_HTML_Tag_Processor($html);
                if ($tags->next_tag('BODY') && $tags->get_attribute('inert') === null) {
                    $tags->set_attribute('inert', true);
                    $tags->set_attribute('data-hachitech-ab-inert', '');
                }
                return $tags->get_updated_html();
            });
        } catch (\Throwable $error) {
            error_log('HideGuard page check failed: ' . $error->getMessage());
            wp_die(esc_html__('Unable to verify access.', 'hachitechs-hideguard'), '', ['response' => 503]);
        }
    }

    public function routes(): void
    {
        foreach (['check', 'verdict', 'fail', 'state'] as $action) {
            register_rest_route('hachitech-hideguard/v1', '/' . $action, [
                'methods' => 'POST', 'callback' => [$this, 'access'], 'permission_callback' => [$this, 'csrf'],
            ]);
        }
    }

    public function csrf(WP_REST_Request $request): bool|WP_Error
    {
        if (strlen($request->get_body()) > 65536) {
            return new WP_Error('hideguard_body', 'Request body is too large.', ['status' => 413]);
        }
        $nonce = $request->get_param('_hg_nonce');
        if (!is_string($nonce) || !wp_verify_nonce($nonce, $this->services->identity->nonceAction())) {
            return new WP_Error('hideguard_csrf', 'Invalid browser nonce. Reload the page.', ['status' => 400]);
        }
        return true;
    }

    public function access(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $context = $this->services->identity->context();
            $policy = $this->services->settings->policy();
            $guard = $this->services->guard();
            $verificationId = $request->get_param('verificationId');
            $verificationId = is_string($verificationId) ? $verificationId : '';
            $raw = $request->get_param('response');
            $payload = is_string($raw) && strlen($raw) <= 16384 ? json_decode($raw, true, 16) : null;
            $route = strtolower($request->get_route());
            // Malformed and unsigned reports reach Guard so a current attempt fails closed.
            $decision = match (true) {
                str_ends_with($route, '/state') => $guard->inspect($context, $policy),
                str_ends_with($route, '/fail') => $guard->failVerification(
                    $context,
                    $policy, $verificationId
                ),
                str_ends_with($route, '/verdict') => $guard->report(
                    $context,
                    $policy, is_array($payload) ? $payload : [], $verificationId
                ),
                default => $guard->preflight($context, $policy, $request->get_param('retry') === '1'),
            };
            if ($decision->status === 'allowed' && !str_ends_with($route, '/state')) {
                $this->commerce->bindCurrent();
            }
            $response = new WP_REST_Response($decision->toBrowserArray(), match ($decision->status) {
                'blocked' => 401, 'verification_failed' => 403, default => 200,
            });
            if ($decision->status === 'blocked') {
                $response->header('Retry-After', (string)max(1, $decision->retryAfterSeconds));
            }
            return $response;
        } catch (\Throwable $error) {
            return RestErrors::response($error);
        }
    }

    public function privateResponse(
        WP_REST_Response $response,
        \WP_REST_Server $server,
        WP_REST_Request $request
    ): WP_REST_Response {
        if (str_starts_with(strtolower($request->get_route()), '/hachitech-hideguard/v1/')) {
            $response->header('Cache-Control', 'private, no-store, max-age=0');
        }
        return $response;
    }

    /** Synchronous bootstrap precedes native WordPress scripts; component initialization stays unchanged. */
    public function networkBootstrap(): void
    {
        if (!$this->protected) {
            return;
        }
        $config = $this->browserConfig();
        $source = (string)file_get_contents(dirname(__DIR__) . '/assets/request-gate.js');
        $settings = wp_json_encode([
            'enabled' => $config['enabled'], 'sdkOrigin' => $config['endpointUrl'],
            // The embedded SDK 1.7 uses this additional origin for its SYN probe.
            'sdkOrigins' => apply_filters('hachitechs_hideguard_sdk_origins', ['https://syn.hideguard.io']),
            'verificationUrls' => [
                $config['accessUrl'], $config['verdictUrl'], $config['failUrl'], $config['stateUrl'],
            ],
            'initialState' => $this->initialState, 'maxWaitMs' => 45000,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        wp_print_inline_script_tag(
            $source . "\nwindow.HachitechsHideguardNetwork.install(" . $settings . ');',
            ['id' => 'hachitechs-hideguard-request-gate']
        );
    }

    public function prioritizeJqueryGate(): void
    {
        if (!$this->protected) {
            return;
        }
        $scripts = wp_scripts();
        $bridge = 'window.HachitechsHideguardNetwork?.getGate()?.attachJQuery(window.jQuery);';
        $after = $scripts->get_data('jquery-core', 'after') ?: [];
        if (!in_array($bridge, $after, true)) {
            // Preserve other inline initializers, but install the prefilter before they can start AJAX timers.
            array_unshift($after, $bridge);
            $scripts->add_data('jquery-core', 'after', $after);
        }
    }

    public function enqueue(): void
    {
        if (!$this->loadRuntime) {
            return;
        }
        wp_enqueue_script_module('hachitech-hideguard', plugins_url('assets/access.js', $this->file), [], '1.5.0');
        wp_register_style('hachitech-hideguard', false, [], '1.5.0');
        wp_enqueue_style('hachitech-hideguard');
        wp_add_inline_style('hachitech-hideguard', json_decode((string)file_get_contents(
            dirname(__DIR__) . '/assets/runtime.styles.json'
        ), true, 512, JSON_THROW_ON_ERROR));
    }

    public function prepareLogin(): void
    {
        if (!$this->services->settings->backendEnabled()) {
            return;
        }
        $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? $_REQUEST['action'] : 'login';
        if (!in_array($action, ['login', 'register'], true)) {
            return;
        }
        $this->loadRuntime = true;
        $this->protected = true;
        $this->returnUrl = home_url('/');
        $this->privateHeaders();
        $this->services->identity->context();
    }

    public function loginModules(): void
    {
        if ($this->loadRuntime) {
            $modules = wp_script_modules();
            $modules->print_import_map();
            $modules->print_enqueued_script_modules();
            $modules->print_script_module_preloads();
            $modules->print_script_module_data();
        }
    }

    public function browserConfig(): array
    {
        $policy = $this->services->settings->policy();
        $backend = $this->services->settings->backendEnabled();
        $blocked = $backend && $this->services->guard()->inspect(
            $this->services->identity->context(),
            $policy
        )->status === 'blocked';
        $gateUrl = add_query_arg(['hachitech_hideguard_gate' => '1', 'return' => $this->returnUrl], home_url('/'));
        return [
            'enabled' => $policy->enabled, 'pageProtected' => $this->protected || $blocked,
            'page' => $this->gate ? 'gate' : '',
            'initialState' => $this->initialState, 'publicKey' => $policy->publicKey,
            'endpointUrl' => $policy->endpointUrl,
            'accessUrl' => rest_url('hachitech-hideguard/v1/check'),
            'verdictUrl' => rest_url('hachitech-hideguard/v1/verdict'),
            'verificationId' => $this->verificationId, 'failUrl' => rest_url('hachitech-hideguard/v1/fail'),
            'gateUrl' => $gateUrl, 'deniedUrl' => add_query_arg('failed', '1', $gateUrl),
            'returnUrl' => $this->returnUrl,
            'nonce' => wp_create_nonce($this->services->identity->nonceAction()),
            'restNonce' => wp_create_nonce('wp_rest'),
            'backendEvents' => $backend, 'stateUrl' => rest_url('hachitech-hideguard/v1/state'),
            'protectLogin' => $backend && isset(ActionEvents::EVENTS['login']),
            'protectRegister' => $backend && isset(ActionEvents::EVENTS['register']),
            'protectPayment' => $backend && isset(ActionEvents::EVENTS['payment']),
            'protectCart' => $policy->protects('cart') || ($backend && isset(ActionEvents::EVENTS['add_to_cart'])),
            'protectCheckout' => $policy->protects('checkout') || $policy->protects('complete_order') || $backend,
        ];
    }

    public function reset(): never
    {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.', '', ['response' => 403]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die('Use POST to reset blocks.', '', ['response' => 405]);
        }
        check_admin_referer('hachitech_hideguard_reset');
        try {
            $this->services->guard()->resetBlocks((string)get_current_blog_id());
        } catch (\Throwable $error) {
            error_log('HideGuard block reset failed: ' . $error->getMessage());
            wp_die('Unable to reset blocks. Please retry.', '', ['response' => 503]);
        }
        wp_safe_redirect(admin_url('options-general.php?page=' . Settings::OPTION));
        exit;
    }

    public function settingsPage(): void
    {
        if (current_user_can('manage_options')) {
            require dirname(__DIR__) . '/views/settings.php';
        }
    }

    private function privateHeaders(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
    }

    private function renderGate(): never
    {
        $this->gate = true;
        require dirname(__DIR__) . '/views/gate.php';
        exit;
    }

    private function safeReturn(string $value): string
    {
        if (str_contains($value, '\\') || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            return home_url('/');
        }
        $candidate = remove_query_arg('hachitech_hideguard_gate', wp_validate_redirect($value, home_url('/')));
        if (str_starts_with($candidate, '/') && !str_starts_with($candidate, '//')) {
            return $candidate;
        }
        $home = wp_parse_url(home_url('/'));
        $target = wp_parse_url($candidate);
        foreach (['scheme', 'host', 'port'] as $part) {
            if (($home[$part] ?? null) !== ($target[$part] ?? null)) {
                return home_url('/');
            }
        }
        if (isset($target['user']) || isset($target['pass'])) {
            return home_url('/');
        }
        return remove_query_arg(['hachitech_hideguard_gate', 'failed', 'return'], $candidate);
    }
}
