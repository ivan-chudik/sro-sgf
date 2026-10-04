<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin-only preview endpoints:
 *   - order confirmation email  (for a participant)
 *   - thank-you page            (for an order)
 *   - Stripe receipt HTML       (for an order) — reuses StripeManager
 *
 * All endpoints require `manage_woocommerce` capability and a valid nonce,
 * and render inline HTML in a new tab — nothing is sent, nothing is stored.
 */
class AdminPreview
{
    /** @var WooCommerceManager */
    private $wooCommerceManager;

    /** @var ParticipantManager */
    private $participantManager;

    /** @var StripeManager */
    private $stripeManager;

    public function __construct(
        WooCommerceManager $wooCommerceManager,
        ParticipantManager $participantManager,
        StripeManager $stripeManager
    ) {
        $this->wooCommerceManager = $wooCommerceManager;
        $this->participantManager = $participantManager;
        $this->stripeManager = $stripeManager;
    }

    public function registerHooks(): void
    {
        add_action('admin_post_alttag_preview_email', [$this, 'previewEmail']);
        add_action('admin_post_alttag_preview_thankyou', [$this, 'previewThankYou']);
        add_action('admin_post_alttag_preview_stripe_receipt', [$this, 'previewStripeReceipt']);

        // Meta box on WooCommerce order edit screen
        add_action('add_meta_boxes', [$this, 'registerOrderMetaBox']);
    }

    // =========================================================================
    // Meta box on WC order edit
    // =========================================================================

    public function registerOrderMetaBox(): void
    {
        // Classic posts screen
        add_meta_box(
            'alttag_order_preview',
            __('Previews', 'alttag-registrations'),
            [$this, 'renderOrderMetaBox'],
            'shop_order',
            'side',
            'default'
        );
        // HPOS (high-performance order storage) screen
        add_meta_box(
            'alttag_order_preview',
            __('Previews', 'alttag-registrations'),
            [$this, 'renderOrderMetaBox'],
            'woocommerce_page_wc-orders',
            'side',
            'default'
        );
    }

    public function renderOrderMetaBox($post_or_order): void
    {
        $order = $this->resolveOrder($post_or_order);
        if (!$order instanceof \WC_Order) {
            return;
        }

        $order_id = $order->get_id();
        $participant = $this->participantManager->getParticipantByOrderId($order_id);
        $participant_id = $participant ? (int) $participant->ID : 0;
        $btn_row = 'white-space: normal; text-align: center; line-height: normal; '
            . 'padding: 6px; display: block; margin-bottom: 6px;';

        echo '<p style="margin: 0 0 8px 0;">'
            . esc_html__('Open in a new tab — nothing is sent, nothing is stored.', 'alttag-registrations')
            . '</p>';

        if ($participant_id) {
            echo '<a href="' . esc_url(self::emailUrl($participant_id)) . '" target="_blank" rel="noopener" '
                . 'class="button" style="' . esc_attr($btn_row) . '">'
                . esc_html__('Preview email', 'alttag-registrations') . '</a>';
        } else {
            echo '<p><em>' . esc_html__('No participant yet — email preview unavailable.', 'alttag-registrations')
                . '</em></p>';
        }

        echo '<a href="' . esc_url(self::thankYouUrl($order_id)) . '" target="_blank" rel="noopener" '
            . 'class="button" style="' . esc_attr($btn_row) . '">'
            . esc_html__('Preview thank-you page', 'alttag-registrations') . '</a>';

        echo '<a href="' . esc_url(self::stripeReceiptUrl($order_id)) . '" target="_blank" rel="noopener" '
            . 'class="button" style="' . esc_attr($btn_row) . '">'
            . esc_html__('Preview Stripe receipt', 'alttag-registrations') . '</a>';
    }

    // =========================================================================
    // URL helpers (so UI code can easily build preview links)
    // =========================================================================

    public static function emailUrl(int $participant_id): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=alttag_preview_email&participant_id=' . $participant_id),
            'alttag_preview_email_' . $participant_id
        );
    }

    public static function thankYouUrl(int $order_id): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=alttag_preview_thankyou&order_id=' . $order_id),
            'alttag_preview_thankyou_' . $order_id
        );
    }

    public static function stripeReceiptUrl(int $order_id): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=alttag_preview_stripe_receipt&order_id=' . $order_id),
            'alttag_preview_receipt_' . $order_id
        );
    }

    // =========================================================================
    // Handlers
    // =========================================================================

    public function previewEmail(): void
    {
        $participant_id = absint($_GET['participant_id'] ?? 0);
        $this->assertCapability();
        $this->assertNonce('alttag_preview_email_' . $participant_id);

        if (!$participant_id) {
            wp_die(esc_html__('Missing participant_id.', 'alttag-registrations'), '', ['response' => 400]);
        }

        $participant_details = $this->participantManager->getParticipantDetails($participant_id);
        if (!$participant_details) {
            wp_die(esc_html__('Participant not found.', 'alttag-registrations'), '', ['response' => 404]);
        }

        $order_id = $participant_details['order_id'] ?? null;
        $order = $order_id ? wc_get_order($order_id) : null;

        // Set up context so template placeholders resolve correctly
        if ($order) {
            ctx()->withOrder($order);
        }
        ctx()->withParticipant($participant_id);

        // Mimic the language selection done by handleResendEmail
        $lang = $participant_details['language'] ?? '';
        if (!empty($lang) && $order) {
            $order->update_meta_data('_language', $lang);
        }

        $order_data = $participant_details;
        $order_data['participant_id'] = $participant_id;
        if ($order) {
            $order_data['order_status'] = $order->get_status();
        }

        $built = $this->wooCommerceManager->buildOrderEmail($order_data);
        if (!$built || empty($built['html'])) {
            wp_die(esc_html__('Could not build email preview.', 'alttag-registrations'));
        }

        // Fire the "after" hook so language switchers restore state
        do_action('alttag_registrations_after_order_email', $built['order']);

        $this->sendPreviewHeaders();
        $this->renderPreviewShell(
            sprintf(
                /* translators: %1$s = subject, %2$d = order id, %3$d = participant id */
                __('Email preview — %1$s (order #%2$d, participant #%3$d)', 'alttag-registrations'),
                $built['subject'],
                (int) ($order_id ?: 0),
                $participant_id
            ),
            $built['html'],
            [
                'Subject' => $built['subject'],
                'Attachments' => implode(', ', array_map('basename', $built['attachments'])) ?: '—',
                'Recipient (real send would go to)' => $participant_details['email'] ?? '—',
            ]
        );
        exit;
    }

    public function previewThankYou(): void
    {
        $order_id = absint($_GET['order_id'] ?? 0);
        $this->assertCapability();
        $this->assertNonce('alttag_preview_thankyou_' . $order_id);

        if (!$order_id) {
            wp_die(esc_html__('Missing order_id.', 'alttag-registrations'), '', ['response' => 400]);
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            wp_die(esc_html__('Order not found.', 'alttag-registrations'), '', ['response' => 404]);
        }

        // Redirect to the real WC order-received URL so the full theme + Elementor
        // stack renders. Passing `key` lets any user (incl. other admins) view it.
        $url = $order->get_checkout_order_received_url();
        $url = add_query_arg('alttag_preview', '1', $url);
        wp_safe_redirect($url);
        exit;
    }

    public function previewStripeReceipt(): void
    {
        $order_id = absint($_GET['order_id'] ?? 0);
        $this->assertCapability();
        $this->assertNonce('alttag_preview_receipt_' . $order_id);

        if (!$order_id) {
            wp_die(esc_html__('Missing order_id.', 'alttag-registrations'), '', ['response' => 400]);
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            wp_die(esc_html__('Order not found.', 'alttag-registrations'), '', ['response' => 404]);
        }

        ctx()->withOrder($order);

        $html = $this->stripeManager->getReceiptHtmlFromOrder($order);
        if (!$html) {
            wp_die(esc_html__('No Stripe receipt available for this order (payment intent missing or not paid via Stripe).', 'alttag-registrations'));
        }

        $this->sendPreviewHeaders();
        // Stripe receipt is a complete HTML document — output as-is
        echo $html;
        exit;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Resolve a WC order from either a WC_Order instance or a classic WP_Post-like object.
     */
    private function resolveOrder($post_or_order): ?\WC_Order
    {
        if ($post_or_order instanceof \WC_Order) {
            return $post_or_order;
        }
        $post_id = is_object($post_or_order) ? (int) ($post_or_order->ID ?? 0) : 0;
        $order = $post_id ? wc_get_order($post_id) : null;
        return $order instanceof \WC_Order ? $order : null;
    }

    private function assertCapability(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to preview this.', 'alttag-registrations'), '', ['response' => 403]);
        }
    }

    private function assertNonce(string $action): void
    {
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, $action)) {
            wp_die(esc_html__('Invalid or expired preview link.', 'alttag-registrations'), '', ['response' => 403]);
        }
    }

    private function sendPreviewHeaders(): void
    {
        nocache_headers();
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Robots-Tag: noindex');
        }
    }

    /**
     * Wrap rendered HTML in a slim admin preview shell (banner + content frame).
     */
    private function renderPreviewShell(string $title, string $content, array $meta = []): void
    {
        $meta_html = '';
        foreach ($meta as $k => $v) {
            $meta_html .= '<span style="margin-right:16px;"><strong>' . esc_html((string) $k) . ':</strong> '
                . esc_html((string) $v) . '</span>';
        }

        $banner = '<div style="position:sticky;top:0;z-index:9999;background:#1d2327;color:#fff;'
            . 'padding:10px 16px;font:13px/1.4 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;'
            . 'border-bottom:1px solid #2c3338;">'
            . '<strong style="margin-right:16px;">' . esc_html__('ADMIN PREVIEW', 'alttag-registrations') . '</strong>'
            . esc_html($title)
            . '<div style="margin-top:6px;opacity:.75;font-size:12px;">' . $meta_html . '</div>'
            . '</div>';

        echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html($title) . '</title></head><body style="margin:0;">';
        echo $banner;
        echo '<div style="padding:24px;max-width:800px;margin:0 auto;background:#f0f0f1;min-height:calc(100vh - 80px);">';
        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;overflow:hidden;">';
        echo $content;
        echo '</div></div>';
        echo '</body></html>';
    }
}
