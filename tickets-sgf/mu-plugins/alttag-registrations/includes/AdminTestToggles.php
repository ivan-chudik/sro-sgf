<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-user testing switches for administrators, exposed in the admin bar.
 *
 * Two independent toggles, both stored in user meta so they can never change
 * behaviour for ordinary visitors or for another administrator:
 *
 *   - Stripe test card      - forces Payment Plugins for Stripe WooCommerce
 *                             (woo-stripe-payment) into test mode for this
 *                             user's requests only, via the `wc_stripe_mode`
 *                             filter. The gateway then reads its own
 *                             `publishable_key_test` / `secret_key_test`
 *                             settings; the live keys are never written to or
 *                             overwritten.
 *   - SuperFaktura invoices - whether an invoice may be created for orders
 *                             placed by this user. Default is OFF, i.e. test
 *                             orders placed by an admin do NOT produce an
 *                             invoice until the admin switches it on.
 *
 * Both toggles are inert unless the acting user passes the capability check,
 * and every code path re-checks it rather than trusting the stored meta.
 */
class AdminTestToggles
{
    /** Capability required to see and use the toggles. */
    const CAPABILITY = 'manage_woocommerce';

    /** User meta: '1' forces Stripe into test mode for this user. */
    const META_STRIPE_TEST = '_alttag_test_stripe_test_mode';

    /** User meta: '1' allows SuperFaktura invoices for this user's orders. */
    const META_SF_INVOICES = '_alttag_test_sf_invoices';

    /** Order meta stamped at checkout when invoices must be skipped. */
    const ORDER_META_SKIP_SF = '_alttag_test_skip_sf_invoice';

    public function registerHooks()
    {
        add_action('admin_bar_menu', [$this, 'addAdminBarNodes'], 100);
        add_action('admin_post_alttag_test_toggle', [$this, 'handleToggle']);

        // The panel lives in the footer of both the front end and wp-admin,
        // because the admin bar - its entry point - is shown on both.
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_footer', [$this, 'renderPanel']);
        add_action('admin_footer', [$this, 'renderPanel']);

        // The toggles must be applied on the front end as well, so hook on
        // `init` rather than on an admin-only action.
        add_action('init', [$this, 'applyToggles'], 20);

        add_action('admin_notices', [$this, 'maybeShowMissingKeysNotice']);
    }

    // =========================================================================
    // State
    // =========================================================================

    /**
     * Whether the current user may use the testing toggles at all.
     */
    public static function userCanTest($user_id = null)
    {
        if ($user_id === null) {
            return is_user_logged_in() && current_user_can(self::CAPABILITY);
        }

        return user_can($user_id, self::CAPABILITY);
    }

    /**
     * Read a toggle for a user. Returns false for users without the capability
     * even if stale meta says otherwise (capability may have been revoked).
     */
    public static function isEnabled($meta_key, $user_id = null)
    {
        $user_id = $user_id ?: get_current_user_id();

        if (!$user_id || !self::userCanTest($user_id)) {
            return false;
        }

        return get_user_meta($user_id, $meta_key, true) === '1';
    }

    // =========================================================================
    // Toggle effects
    // =========================================================================

    public function applyToggles()
    {
        if (!self::userCanTest()) {
            return;
        }

        if (self::isEnabled(self::META_STRIPE_TEST)) {
            $this->enableStripeTestMode();
        }

        // Always registered for a capable user: the filter itself decides per
        // order, so orders stamped in an earlier request are still honoured.
        add_filter('sf_generate_invoice', [$this, 'filterGenerateInvoice'], 20, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'stampOrder'], 10, 1);
    }

    /**
     * Force woo-stripe-payment into test mode for this request only.
     *
     * `wc_stripe_mode()` is the single source of truth in woo-stripe-payment
     * 3.3.x: the secret and publishable key getters both derive their option
     * name from it (`secret_key_{$mode}`), so filtering the mode is enough and
     * no key is ever read from, or written to, the live settings.
     */
    private function enableStripeTestMode()
    {
        if (!function_exists('wc_stripe_mode')) {
            return;
        }

        add_filter('wc_stripe_mode', function () {
            return 'test';
        }, 99);
    }

    /**
     * Skip SuperFaktura invoice creation for an admin's test order.
     *
     * @param bool      $generate Whether SuperFaktura wants to create an invoice.
     * @param \WC_Order $order    The order being processed.
     * @return bool
     */
    public function filterGenerateInvoice($generate, $order)
    {
        if (!$generate || !$order instanceof \WC_Order) {
            return $generate;
        }

        if ($order->get_meta(self::ORDER_META_SKIP_SF) === '1') {
            return false;
        }

        // Orders created before this feature existed, or outside checkout, are
        // only affected while the owning admin is the one acting.
        $customer_id = (int) $order->get_customer_id();
        if ($customer_id && $customer_id === get_current_user_id()
            && !self::isEnabled(self::META_SF_INVOICES)
        ) {
            return false;
        }

        return $generate;
    }

    /**
     * Stamp the order at checkout so later status transitions - which may run
     * in a webhook or cron request with no acting user - still skip the
     * invoice.
     *
     * @param \WC_Order $order
     */
    public function stampOrder($order)
    {
        if (!self::userCanTest() || self::isEnabled(self::META_SF_INVOICES)) {
            return;
        }

        $order->update_meta_data(self::ORDER_META_SKIP_SF, '1');
    }

    // =========================================================================
    // Admin bar
    // =========================================================================

    /**
     * The admin bar node is the entry point only: a single node that opens the
     * side panel. The toggles themselves live in the panel, not in a dropdown.
     *
     * @param \WP_Admin_Bar $admin_bar
     */
    public function addAdminBarNodes($admin_bar)
    {
        if (!self::userCanTest()) {
            return;
        }

        $any_on = self::isEnabled(self::META_STRIPE_TEST) || self::isEnabled(self::META_SF_INVOICES);

        $admin_bar->add_node([
            'id' => 'alttag-test',
            'title' => esc_html__('Test modes', 'alttag-registrations')
                . sprintf(
                    '<span class="alttag-test-dot" data-on="%s" aria-hidden="true">&#9679;</span>',
                    $any_on ? '1' : '0'
                ),
            // Anchors the no-script fallback (the panel opens via :target) and
            // gives the node a keyboard-focusable element for the JS handler.
            'href' => '#alttag-test-panel',
            'meta' => ['title' => __('These toggles only affect your own session.', 'alttag-registrations')],
        ]);
    }

    // =========================================================================
    // Side panel
    // =========================================================================

    public function enqueueAssets()
    {
        if (!self::userCanTest() || !is_admin_bar_showing()) {
            return;
        }

        $cssFile = ALTTAG_REGISTRATIONS_PATH . 'assets/css/admin-test-panel.css';
        wp_enqueue_style(
            'alttag-admin-test-panel',
            ALTTAG_REGISTRATIONS_URL . 'assets/css/admin-test-panel.css',
            [],
            file_exists($cssFile) ? filemtime($cssFile) : '1.0.0'
        );

        $jsFile = ALTTAG_REGISTRATIONS_PATH . 'assets/js/admin-test-panel.js';
        wp_enqueue_script(
            'alttag-admin-test-panel',
            ALTTAG_REGISTRATIONS_URL . 'assets/js/admin-test-panel.js',
            [],
            file_exists($jsFile) ? filemtime($jsFile) : '1.0.0',
            true
        );

        wp_localize_script('alttag-admin-test-panel', 'alttagTestPanel', [
            'i18n' => [
                'error' => __('The toggle could not be changed. Please try again.', 'alttag-registrations'),
            ],
        ]);
    }

    /**
     * The toggles shown in the panel, in display order.
     *
     * @return array<int, array{key: string, label: string, desc: string, on: bool}>
     */
    private function panelToggles()
    {
        $toggles = [
            [
                'key' => self::META_STRIPE_TEST,
                'label' => __('Stripe: test card', 'alttag-registrations'),
                'desc' => __('Pay with the Stripe test card for this session', 'alttag-registrations'),
                'on' => self::isEnabled(self::META_STRIPE_TEST),
            ],
        ];

        // The SuperFaktúra toggle is only meaningful while the plugin that
        // consumes it is active, so hide the row otherwise.
        if (RegistrationContext::current()->hasInvoicing()) {
            $toggles[] = [
                'key' => self::META_SF_INVOICES,
                'label' => __('SuperFaktúra: generate invoices', 'alttag-registrations'),
                'desc' => __('Create a SuperFaktúra invoice for orders you place', 'alttag-registrations'),
                'on' => self::isEnabled(self::META_SF_INVOICES),
            ];
        }

        return $toggles;
    }

    /**
     * Renders the slide-in panel in the footer. Modal: the backdrop blocks the
     * page behind it and the JS traps Tab focus, hence aria-modal="true".
     */
    public function renderPanel()
    {
        if (!self::userCanTest()) {
            return;
        }

        $toggles = $this->panelToggles();

        // Only rendered when the keys really are missing, so that switching the
        // toggle on in the browser can simply unhide it.
        $show_key_warning = !$this->stripeTestKeysConfigured();
        $warning_id = 'alttag-test-warning-stripe';
        ?>
        <div class="alttag-test-panel-backdrop" id="alttag-test-panel-backdrop"></div>
        <?php /* aria-modal="true": the backdrop blocks pointer input and the JS traps Tab focus inside. */ ?>
        <div class="alttag-test-panel" id="alttag-test-panel" role="dialog" aria-modal="true" tabindex="-1"
             aria-label="<?php echo esc_attr__('Test modes', 'alttag-registrations'); ?>">
            <div class="atp-header">
                <h2 class="atp-title"><?php esc_html_e('Test modes', 'alttag-registrations'); ?></h2>
                <button type="button" class="atp-close" aria-label="<?php echo esc_attr__('Close', 'alttag-registrations'); ?>">&times;</button>
            </div>

            <ul class="atp-rows">
                <?php foreach ($toggles as $toggle) : ?>
                    <?php
                    $is_stripe = $toggle['key'] === self::META_STRIPE_TEST;
                    ?>
                    <li class="atp-row<?php echo $toggle['on'] ? ' is-on' : ''; ?>">
                        <?php
                        $label_id = 'atp-label-' . $toggle['key'];
                        $desc_id = 'atp-desc-' . $toggle['key'];
                        ?>
                        <span class="atp-row-text">
                            <span class="atp-label" id="<?php echo esc_attr($label_id); ?>"><?php echo esc_html($toggle['label']); ?></span>
                            <span class="atp-desc" id="<?php echo esc_attr($desc_id); ?>"><?php echo esc_html($toggle['desc']); ?></span>
                        </span>
                        <button type="button"
                                class="atp-switch"
                                role="switch"
                                aria-checked="<?php echo $toggle['on'] ? 'true' : 'false'; ?>"
                                aria-labelledby="<?php echo esc_attr($label_id); ?>"
                                aria-describedby="<?php echo esc_attr($desc_id); ?>"
                                data-toggle="<?php echo esc_attr($toggle['key']); ?>"
                                <?php if ($is_stripe && $show_key_warning) : ?>data-warning="<?php echo esc_attr($warning_id); ?>"<?php endif; ?>
                                data-endpoint="<?php echo esc_url($this->toggleUrl($toggle['key'], true)); ?>"></button>
                        <?php if ($is_stripe && $show_key_warning) : ?>
                            <p class="atp-warning" id="<?php echo esc_attr($warning_id); ?>"
                               <?php echo self::isEnabled(self::META_STRIPE_TEST) ? '' : 'hidden'; ?>>
                                <?php esc_html_e('Stripe test keys are not configured.', 'alttag-registrations'); ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=stripe_api')); ?>"><?php
                                    esc_html_e('Open Stripe API settings', 'alttag-registrations');
                                ?></a>
                            </p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="atp-footer">
                <?php esc_html_e('These toggles only affect your own session.', 'alttag-registrations'); ?>
                <noscript>
                    <ul>
                        <?php foreach ($toggles as $toggle) : ?>
                            <li><a href="<?php echo esc_url($this->toggleUrl($toggle['key'])); ?>"><?php
                                echo esc_html($toggle['label']);
                            ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </noscript>
            </div>
        </div>
        <?php
    }

    /**
     * @param string $meta_key
     * @param bool   $json Ask handleToggle() for a JSON reply instead of a redirect.
     */
    private function toggleUrl($meta_key, $json = false)
    {
        $args = [
            'action' => 'alttag_test_toggle',
            'toggle' => rawurlencode($meta_key),
        ];

        if ($json) {
            $args['output'] = 'json';
        }

        return wp_nonce_url(
            add_query_arg($args, admin_url('admin-post.php')),
            'alttag_test_toggle_' . $meta_key
        );
    }

    /**
     * Whether the caller asked for a JSON reply rather than a redirect. The
     * panel's switches post to the same URL as the no-script links, so the
     * capability and nonce checks below are identical either way.
     */
    private function wantsJson()
    {
        if (isset($_GET['output']) && $_GET['output'] === 'json') {
            return true;
        }

        $accept = isset($_SERVER['HTTP_ACCEPT']) ? (string) $_SERVER['HTTP_ACCEPT'] : '';

        return stripos($accept, 'application/json') !== false;
    }

    public function handleToggle()
    {
        if (!self::userCanTest()) {
            wp_die(esc_html__('You are not allowed to change testing toggles.', 'alttag-registrations'), '', ['response' => 403]);
        }

        $meta_key = isset($_GET['toggle']) ? sanitize_text_field(wp_unslash($_GET['toggle'])) : '';

        if (!in_array($meta_key, [self::META_STRIPE_TEST, self::META_SF_INVOICES], true)) {
            wp_die(esc_html__('Unknown testing toggle.', 'alttag-registrations'), '', ['response' => 400]);
        }

        check_admin_referer('alttag_test_toggle_' . $meta_key);

        $user_id = get_current_user_id();
        $new = get_user_meta($user_id, $meta_key, true) === '1' ? '0' : '1';
        update_user_meta($user_id, $meta_key, $new);

        if ($this->wantsJson()) {
            wp_send_json_success([
                'key' => $meta_key,
                'new_state' => $new,
            ]);
        }

        $referer = wp_get_referer();
        wp_safe_redirect($referer ?: admin_url());
        exit;
    }

    // =========================================================================
    // Notices
    // =========================================================================

    /**
     * Whether woo-stripe-payment has both test keys filled in.
     */
    private function stripeTestKeysConfigured()
    {
        if (!function_exists('wc_stripe_get_secret_key') || !function_exists('wc_stripe_get_publishable_key')) {
            return false;
        }

        return !empty(wc_stripe_get_secret_key('test'))
            && !empty(wc_stripe_get_publishable_key('test'));
    }

    public function maybeShowMissingKeysNotice()
    {
        if (!self::userCanTest() || !self::isEnabled(self::META_STRIPE_TEST)) {
            return;
        }

        if (!function_exists('wc_stripe_mode')) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html__('The "Stripe: test card" toggle is on, but the Stripe gateway plugin is not active.', 'alttag-registrations')
            );
            return;
        }

        if ($this->stripeTestKeysConfigured()) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html__('The "Stripe: test card" toggle is on, but no Stripe test API keys are configured. Fill in the test publishable and secret key in WooCommerce → Settings → Payments → API Settings.', 'alttag-registrations')
        );
    }
}
