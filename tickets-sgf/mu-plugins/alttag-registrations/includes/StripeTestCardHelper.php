<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders a Stripe TEST-mode helper box on the WooCommerce checkout with
 * click-to-copy values for the standard 4242… test card. Active only when
 * Payment Plugins for Stripe WooCommerce (woo-stripe-payment) reports test
 * mode via wc_stripe_test_mode().
 *
 * Auto-fill is intentionally not attempted: Stripe Elements iframes are
 * cross-origin sandboxed and block programmatic input by design.
 */
class StripeTestCardHelper
{
    public function registerHooks()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('woocommerce_review_order_before_payment', [$this, 'render']);
    }

    public function enqueueAssets()
    {
        if (!function_exists('wc_stripe_test_mode') || !wc_stripe_test_mode()) {
            return;
        }
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        $cssFile = ALTTAG_REGISTRATIONS_PATH . 'assets/css/stripe-test-card-helper.css';
        wp_enqueue_style(
            'alttag-stripe-test-card-helper',
            ALTTAG_REGISTRATIONS_URL . 'assets/css/stripe-test-card-helper.css',
            [],
            file_exists($cssFile) ? filemtime($cssFile) : '1.0.0'
        );

        $jsFile = ALTTAG_REGISTRATIONS_PATH . 'assets/js/stripe-test-card-helper.js';
        wp_enqueue_script(
            'alttag-stripe-test-card-helper',
            ALTTAG_REGISTRATIONS_URL . 'assets/js/stripe-test-card-helper.js',
            [],
            file_exists($jsFile) ? filemtime($jsFile) : '1.0.0',
            true
        );

        wp_localize_script('alttag-stripe-test-card-helper', 'alttagStripeTestCard', [
            'i18n' => [
                'copied' => __('Copied', 'alttag-registrations'),
            ],
        ]);
    }

    public function render()
    {
        if (!function_exists('wc_stripe_test_mode') || !wc_stripe_test_mode()) {
            return;
        }

        /**
         * Allow callers to hide the helper even when Stripe is in test mode.
         */
        if (!apply_filters('alttag_registrations_show_stripe_test_card_helper', true)) {
            return;
        }

        $card = '4242 4242 4242 4242';
        $card_raw = str_replace(' ', '', $card);
        ?>
        <div class="stripe-test-card-helper" role="note" aria-label="<?php echo esc_attr(__('Stripe test card', 'alttag-registrations')); ?>">
            <div class="stch-header">
                <strong><?php esc_html_e('Stripe TEST mode', 'alttag-registrations'); ?></strong>
                <span><?php esc_html_e('Test card for verifying the checkout', 'alttag-registrations'); ?></span>
            </div>
            <div class="stch-row">
                <span class="stch-label"><?php esc_html_e('Card number', 'alttag-registrations'); ?></span>
                <code class="stch-value"><?php echo esc_html($card); ?></code>
                <button
                    type="button"
                    class="stch-copy"
                    data-stch-copy="<?php echo esc_attr($card_raw); ?>"
                ><?php esc_html_e('Copy', 'alttag-registrations'); ?></button>
            </div>
            <p class="stch-hint">
                <?php
                printf(
                    /* translators: %s: stripe.com/docs/testing link */
                    esc_html__('Expiry: any future date (month/year later than the current one). CVC: any 3-digit number. More test cards: %s', 'alttag-registrations'),
                    '<a href="https://stripe.com/docs/testing" target="_blank" rel="noopener">stripe.com/docs/testing</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }
}
