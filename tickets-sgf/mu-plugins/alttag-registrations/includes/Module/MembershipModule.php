<?php

namespace Alttag\Registrations\Module;

use function Alttag\Registrations\ctx;
use Alttag\Registrations\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Treats products in a designated taxonomy term as "memberships" and applies
 * configurable copy/UX overrides at checkout, in emails, on Stripe receipts,
 * and on the SuperFaktura invoice.
 *
 * Per-project knobs (all live in the Memberships tab):
 *   - product_category    (slug used to detect membership products)
 *   - field labels        (override Meno / Priezvisko / Email at checkout)
 *   - invoice participant_label (replaces "Participant:" in SF comment)
 *   - email_title_paid / email_title_unpaid                  ({product_name})
 *   - payment_confirmation_text / registration_confirmation_text ({product_name})
 *   - receipt_title_template / receipt_heading_template / receipt_subject_template
 *   - hide_receipt_info_text (drop info text from Stripe receipt)
 */
class MembershipModule extends AbstractModule
{
    public function getId(): string
    {
        return 'membership';
    }

    public function getName(): string
    {
        return __('Memberships', 'alttag-registrations');
    }

    public function getDescription(): string
    {
        return __(
            'Detect membership products by category and override checkout field labels,'
            . ' email title/body, Stripe receipt and SuperFaktura invoice text.',
            'alttag-registrations'
        );
    }

    public function getSettingsTab(): ?string
    {
        return 'modules';
    }

    public function getSettingsFields(): array
    {
        return [
            'membership' => [
                'product_category' => [
                    'label' => __('Membership product category slug', 'alttag-registrations'),
                    'type' => 'text',
                    'description' => __(
                        'Product category slug used to detect membership products (e.g. "membership-product").',
                        'alttag-registrations'
                    ),
                ],
                'first_name_label' => [
                    'label' => __('Checkout – first name field label', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                ],
                'last_name_label' => [
                    'label' => __('Checkout – last name field label', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                ],
                'email_label' => [
                    'label' => __('Checkout – email field label', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                ],
                'invoice_participant_label' => [
                    'label' => __('SuperFaktura invoice participant label', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_membership_invoice_participant_label',
                    'description' => __(
                        'Replaces "Participant:" in SF comment for membership orders.',
                        'alttag-registrations'
                    ),
                ],
                'email_title_paid' => [
                    'label' => __('Email title (paid)', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_membership_email_title_paid',
                    'description' => __('Supports {product_name}.', 'alttag-registrations'),
                ],
                'email_title_unpaid' => [
                    'label' => __('Email title (unpaid)', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_membership_email_title_unpaid',
                    'description' => __('Supports {product_name}.', 'alttag-registrations'),
                ],
                'payment_confirmation_text' => [
                    'label' => __('Payment-confirmation body text', 'alttag-registrations'),
                    'type' => 'textarea',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_membership_payment_text',
                    'description' => __('Supports {product_name}.', 'alttag-registrations'),
                ],
                'registration_confirmation_text' => [
                    'label' => __('Registration-confirmation body text', 'alttag-registrations'),
                    'type' => 'textarea',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_membership_registration_text',
                    'description' => __('Supports {product_name}.', 'alttag-registrations'),
                ],
                'receipt_title_template' => [
                    'label' => __('Stripe receipt "from" template', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_membership_receipt_title',
                    'description' => __('Supports {product_name}.', 'alttag-registrations'),
                ],
                'receipt_heading_template' => [
                    'label' => __('Stripe receipt heading template', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_membership_receipt_heading',
                    'description' => __(
                        'Supports {product_name}. Replaces "Your {event} receipt" portion.',
                        'alttag-registrations'
                    ),
                ],
                'receipt_subject_template' => [
                    'label' => __('Stripe receipt email subject template', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_membership_receipt_subject',
                    'description' => __('Supports {product_name}.', 'alttag-registrations'),
                ],
                'hide_receipt_info_text' => [
                    'label' => __('Hide info text on Stripe receipts', 'alttag-registrations'),
                    'type' => 'checkbox',
                    'product_override' => true,
                    'product_meta_key' => '_alttag_membership_hide_receipt_info_text',
                ],
                'checkout_heading_paid' => [
                    'label' => __('Thank-you heading (paid)', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_membership_checkout_heading_paid',
                    'description' => __('Supports {product_name}.', 'alttag-registrations'),
                ],
                'checkout_heading_unpaid' => [
                    'label' => __('Thank-you heading (unpaid)', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_membership_checkout_heading_unpaid',
                    'description' => __('Supports {product_name}.', 'alttag-registrations'),
                ],
                'checkout_greeting_template' => [
                    'label' => __('Thank-you greeting template', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'description' => __(
                        'Replaces standard greeting on the thank-you page. Supports {full_name}.',
                        'alttag-registrations'
                    ),
                ],
                'thankyou_box_title_template' => [
                    'label' => __('Thank-you info box title template', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_membership_thankyou_box_title',
                    'description' => __('Supports {product_name}.', 'alttag-registrations'),
                ],
                'thankyou_box_body' => [
                    'label' => __('Thank-you info box body', 'alttag-registrations'),
                    'type' => 'textarea',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_membership_thankyou_box_body',
                ],
            ],
        ];
    }

    public function registerHooks(): void
    {
        // Checkout field labels (priority 999 to win over WC + SF)
        add_filter('woocommerce_form_field_args', [$this, 'applyCheckoutLabels'], 999, 3);

        // Email title / subject / event details / payment + registration text
        add_filter('alttag_registrations_email_title', [$this, 'filterEmailTitle'], 6, 3);
        add_filter('alttag_registrations_email_subject', [$this, 'filterParticipantSubject'], 6, 3);
        add_filter('alttag_registrations_email_event_details', [$this, 'filterEventDetails'], 6, 3);
        add_filter('alttag_registrations_email_payment_confirmation_text', [$this, 'filterPaymentText'], 5, 2);
        add_filter('alttag_registrations_email_registration_confirmation_text', [$this, 'filterRegistrationText'], 5, 2);
        add_filter('alttag_registrations_default_email_subject', [$this, 'filterDefaultSubject'], 5, 2);

        // Suppress QR code in confirmation email (memberships have no ticket)
        add_filter('alttag_registrations_email_display_qr_code', [$this, 'filterDisplayQrCode'], 6, 3);

        // Stripe receipt overrides
        add_filter('alttag_registrations_receipt_title', [$this, 'filterReceiptTitle'], 6, 2);
        add_filter('alttag_registrations_receipt_heading', [$this, 'filterReceiptHeading'], 6, 2);
        add_filter('alttag_registrations_receipt_subject', [$this, 'filterReceiptSubject'], 6, 2);
        add_filter('alttag_registrations_receipt_show_info_text', [$this, 'filterReceiptShowInfoText'], 6, 2);

        // SuperFaktúra invoice participant label
        add_filter('alttag_registrations_invoice_data', [$this, 'filterInvoiceParticipantLabel'], 5, 3);

        // Thank-you page overrides
        add_filter('alttag_registrations_checkout_heading', [$this, 'filterCheckoutHeading'], 6, 2);
        add_filter('alttag_registrations_checkout_greeting', [$this, 'filterCheckoutGreeting'], 6, 2);
        add_filter('alttag_registrations_checkout_show_event_details', [$this, 'filterShowEventDetails'], 6, 2);
        add_filter('alttag_registrations_checkout_show_qr_section', [$this, 'filterShowQrSection'], 6, 2);
        add_filter('alttag_registrations_display_ticket_download_link', [$this, 'filterDisplayTicketDownload'], 6, 2);
        add_action('alttag_registrations_checkout_after_download_links', [$this, 'renderThankYouBox'], 6);
    }

    // =========================================================================
    // Membership detection
    // =========================================================================

    /**
     * Whether the configured category slug matches the given product.
     */
    public function isMembershipProduct($product): bool
    {
        $category = $this->setting('product_category');
        if ($category === '') {
            return false;
        }

        $product_id = is_numeric($product) ? (int) $product : ($product ? $product->get_id() : 0);
        if (!$product_id) {
            return false;
        }

        return has_term($category, 'product_cat', $product_id);
    }

    public function orderHasMembership($order): bool
    {
        if (!$order instanceof \WC_Order) {
            return false;
        }

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $this->isMembershipProduct($product)) {
                return true;
            }
        }
        return false;
    }

    public function cartContainsMembership(): bool
    {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            if (!isset($cart_item['product_id'])) {
                continue;
            }
            if ($this->isMembershipProduct($cart_item['product_id'])) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // Checkout field labels
    // =========================================================================

    public function applyCheckoutLabels($args, $key, $value)
    {
        if (!$this->cartContainsMembership()) {
            return $args;
        }

        $map = [
            'billing_first_name' => 'first_name_label',
            'billing_last_name' => 'last_name_label',
            'billing_email' => 'email_label',
        ];

        if (!isset($map[$key])) {
            return $args;
        }

        $label = $this->setting($map[$key]);
        if ($label === '') {
            return $args;
        }

        $args['label'] = $label;
        $args['placeholder'] = $label;
        return $args;
    }

    // =========================================================================
    // Email title / subject / event details
    // =========================================================================

    public function filterEmailTitle($title, $order, $participant_id = null)
    {
        if (!$this->orderHasMembership($order)) {
            return $title;
        }

        $template = $this->titleTemplate($order);
        return $template !== '' ? $this->renderProductTemplate($template, $order) : $title;
    }

    public function filterParticipantSubject($subject, $participant, $participant_id)
    {
        $context = ctx()->withParticipant($participant_id);
        $order = $context->order();

        if (!$this->orderHasMembership($order)) {
            return $subject;
        }

        // titleTemplate already passes $order through setting() for product context
        $template = $this->titleTemplate($order);
        if ($template === '') {
            return $subject;
        }

        $product_name = (string) get_post_meta($participant_id, 'product_name', true);
        if ($product_name === '') {
            $product_name = $context->productName();
        }

        return str_replace('{product_name}', $product_name, $template);
    }

    public function filterEventDetails($content, $order, $participant_id = null)
    {
        if (!$this->orderHasMembership($order)) {
            return $content;
        }

        return ctx()->withOrder($order)->productName();
    }

    public function filterDisplayQrCode($display, $order, $participant_id = null)
    {
        return $this->orderHasMembership($order) ? false : $display;
    }

    public function filterPaymentText($text, $order)
    {
        if (!$this->orderHasMembership($order)) {
            return $text;
        }

        $template = $this->setting('payment_confirmation_text', $order);
        return $template !== '' ? $this->renderProductTemplate($template, $order) : $text;
    }

    public function filterRegistrationText($text, $order)
    {
        if (!$this->orderHasMembership($order)) {
            return $text;
        }

        $template = $this->setting('registration_confirmation_text', $order);
        return $template !== '' ? $this->renderProductTemplate($template, $order) : $text;
    }

    public function filterDefaultSubject($subject, $order)
    {
        if (!$this->orderHasMembership($order)) {
            return $subject;
        }

        $template = $this->titleTemplate($order);
        if ($template === '') {
            return $subject;
        }

        $plain = str_replace('<br>', ' ', $template);
        return $this->renderProductTemplate($plain, $order, false);
    }

    // =========================================================================
    // Stripe receipt overrides
    // =========================================================================

    public function filterReceiptTitle($name, $order = null)
    {
        if (!$this->orderHasMembership($order)) {
            return $name;
        }

        $template = $this->setting('receipt_title_template', $order);
        return $template !== '' ? $this->renderProductTemplate($template, $order, false) : $name;
    }

    public function filterReceiptHeading($title, $order)
    {
        if (!$this->orderHasMembership($order)) {
            return $title;
        }

        $template = $this->setting('receipt_heading_template', $order);
        if ($template === '') {
            return $title;
        }

        // Replace text before "Amount"/"Suma" with the rendered template,
        // preserving the amount/date suffix appended by Stripe.
        $amount_pos = strpos($title, 'Amount');
        if ($amount_pos === false) {
            $amount_pos = strpos($title, 'Suma');
        }

        $suffix = $amount_pos !== false ? substr($title, $amount_pos) : '';
        $heading = $this->renderProductTemplate($template, $order, false);

        return $suffix ? $heading . ' ' . $suffix : $heading;
    }

    public function filterReceiptSubject($subject, $order)
    {
        if (!$this->orderHasMembership($order)) {
            return $subject;
        }

        $template = $this->setting('receipt_subject_template', $order);
        return $template !== '' ? $this->renderProductTemplate($template, $order, false) : $subject;
    }

    public function filterReceiptShowInfoText($show, $order)
    {
        if (!$this->orderHasMembership($order)) {
            return $show;
        }

        return (bool) $this->setting('hide_receipt_info_text', $order) ? false : $show;
    }

    // =========================================================================
    // Thank-you page overrides
    // =========================================================================

    public function filterCheckoutHeading($heading, $order)
    {
        if (!$this->orderHasMembership($order)) {
            return $heading;
        }

        $key = $order->has_status('completed') ? 'checkout_heading_paid' : 'checkout_heading_unpaid';
        $template = (string) $this->setting($key, $order);
        return $template !== '' ? $this->renderProductTemplate($template, $order, false) : $heading;
    }

    public function filterCheckoutGreeting($greeting, $order)
    {
        if (!$this->orderHasMembership($order)) {
            return $greeting;
        }

        $template = (string) $this->setting('checkout_greeting_template', $order);
        if ($template === '') {
            return $greeting;
        }

        $full_name = ctx()->withOrder($order)->participantName();
        if ($full_name === '') {
            $full_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        }

        return str_replace('{full_name}', esc_html($full_name), $template);
    }

    public function filterShowEventDetails($show, $order)
    {
        return $this->orderHasMembership($order) ? false : $show;
    }

    public function filterShowQrSection($show, $order)
    {
        return $this->orderHasMembership($order) ? false : $show;
    }

    public function filterDisplayTicketDownload($display, $order)
    {
        return $this->orderHasMembership($order) ? false : $display;
    }

    public function renderThankYouBox($order)
    {
        if (!$this->orderHasMembership($order)) {
            return;
        }

        $title_template = (string) $this->setting('thankyou_box_title_template', $order);
        $body = (string) $this->setting('thankyou_box_body', $order);
        if ($title_template === '' && $body === '') {
            return;
        }

        $box_style = 'margin: 20px 0; padding: 20px; background-color: #f0f4f8;'
            . ' border-left: 4px solid #3d3d5c; border-radius: 4px;';
        $title_style = 'margin: 0 0 10px 0; font-weight: bold; color: #3d3d5c; font-size: 16px;';
        $body_style = 'margin: 0; color: #3d3d5c; font-size: 14px; line-height: 1.6;';

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product || !$this->isMembershipProduct($product)) {
                continue;
            }

            $title = $title_template !== ''
                ? str_replace('{product_name}', $item->get_name(), $title_template)
                : '';

            echo '<div class="alttag-membership-thankyou-box" style="' . esc_attr($box_style) . '">';
            if ($title !== '') {
                echo '<p style="' . esc_attr($title_style) . '">' . esc_html($title) . '</p>';
            }
            if ($body !== '') {
                echo '<p style="' . esc_attr($body_style) . '">' . wp_kses_post($body) . '</p>';
            }
            echo '</div>';
        }
    }

    // =========================================================================
    // SuperFaktúra invoice participant label
    // =========================================================================

    public function filterInvoiceParticipantLabel($data, $order, $type)
    {
        if (!$this->orderHasMembership($order)) {
            return $data;
        }

        $custom_label = $this->setting('invoice_participant_label', $order);
        if ($custom_label === '') {
            return $data;
        }

        if (!empty($data['comment'])) {
            $default_label = __('Participant:', 'alttag-registrations');
            $data['comment'] = str_replace($default_label, $custom_label, $data['comment']);
        }

        return $data;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Read a module setting, optionally with per-product override resolved
     * from the order's product context.
     */
    private function setting($key, $order = null)
    {
        $product_id = $order instanceof \WC_Order
            ? ctx()->withOrder($order)->productId()
            : null;
        return Settings::getValue('membership.' . $key, $product_id);
    }

    /**
     * Pick paid/unpaid title template based on order status.
     */
    private function titleTemplate($order): string
    {
        if (!$order instanceof \WC_Order) {
            return '';
        }

        $key = $order->get_status() === 'completed' ? 'email_title_paid' : 'email_title_unpaid';
        return (string) $this->setting($key, $order);
    }

    /**
     * Render a {product_name} template against the order context. When
     * $escape is true the product name is HTML-escaped before substitution.
     */
    private function renderProductTemplate(string $template, $order, bool $escape = true): string
    {
        $product_name = ctx()->withOrder($order)->productName();
        if ($escape) {
            $product_name = esc_html($product_name);
        }
        return str_replace('{product_name}', $product_name, $template);
    }
}
