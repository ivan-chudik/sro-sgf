<?php

namespace Alttag\Registrations\Module;

use Alttag\Registrations\Core;
use Alttag\Registrations\Settings;
use function Alttag\Registrations\ctx;
use function Alttag\Registrations\is_livestream_enabled;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Treats products in a designated taxonomy term as "recordings" (post-event
 * video access) and applies configurable copy/UX overrides on the thank-you
 * page and in confirmation emails. Recordings carry no ticket and no QR code.
 *
 * Per-project knobs (all live in the Recordings tab):
 *   - product_category    (slug used to detect recording products)
 *   - email_title_paid / email_title_unpaid                    ({product_name})
 *   - payment_confirmation_text / registration_confirmation_text ({product_name})
 *   - checkout_heading_paid / checkout_heading_unpaid          ({product_name})
 *   - checkout_greeting_template                               ({full_name})
 *   - thankyou_box_title_template / thankyou_box_body          ({product_name})
 */
class RecordingModule extends AbstractModule
{
    public function getId(): string
    {
        return 'recording';
    }

    public function getName(): string
    {
        return __('Recordings', 'alttag-registrations');
    }

    public function getDescription(): string
    {
        return __(
            'Detect recording products by category and override email title/body'
            . ' and the thank-you page (no ticket, no QR).',
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
            'recording' => [
                'product_category' => [
                    'label' => __('Recording product category slug', 'alttag-registrations'),
                    'type' => 'text',
                    'description' => __(
                        'Product category slug used to detect recording products (e.g. "recording-product").',
                        'alttag-registrations'
                    ),
                ],
                'email_title_paid' => [
                    'label' => __('Email title (paid)', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_recording_email_title_paid',
                    'description' => __('Supports {product_name}.', 'alttag-registrations'),
                ],
                'email_title_unpaid' => [
                    'label' => __('Email title (unpaid)', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_recording_email_title_unpaid',
                    'description' => __('Supports {product_name}.', 'alttag-registrations'),
                ],
                'payment_confirmation_text' => [
                    'label' => __('Payment-confirmation body text', 'alttag-registrations'),
                    'type' => 'textarea',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_recording_payment_text',
                    'description' => __('Supports {product_name}.', 'alttag-registrations'),
                ],
                'registration_confirmation_text' => [
                    'label' => __('Registration-confirmation body text', 'alttag-registrations'),
                    'type' => 'textarea',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_recording_registration_text',
                    'description' => __('Supports {product_name}.', 'alttag-registrations'),
                ],
                'checkout_heading_paid' => [
                    'label' => __('Thank-you heading (paid)', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_recording_checkout_heading_paid',
                    'description' => __('Supports {product_name}.', 'alttag-registrations'),
                ],
                'checkout_heading_unpaid' => [
                    'label' => __('Thank-you heading (unpaid)', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_recording_checkout_heading_unpaid',
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
                    'product_meta_key' => '_alttag_recording_thankyou_box_title',
                    'description' => __('Supports {product_name}.', 'alttag-registrations'),
                ],
                'thankyou_box_body' => [
                    'label' => __('Thank-you info box body', 'alttag-registrations'),
                    'type' => 'textarea',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_recording_thankyou_box_body',
                ],
            ],
        ];
    }

    public function registerHooks(): void
    {
        // Email title / subject / event details / payment + registration text
        // Priority 8 to win over CoreFilters::maybeNonMembership* at priority 7.
        add_filter('alttag_registrations_email_title', [$this, 'filterEmailTitle'], 8, 3);
        add_filter('alttag_registrations_email_subject', [$this, 'filterParticipantSubject'], 8, 3);
        add_filter('alttag_registrations_email_event_details', [$this, 'filterEventDetails'], 8, 3);
        $payment_filter = 'alttag_registrations_email_payment_confirmation_text';
        $registration_filter = 'alttag_registrations_email_registration_confirmation_text';
        add_filter($payment_filter, [$this, 'filterPaymentText'], 5, 2);
        add_filter($registration_filter, [$this, 'filterRegistrationText'], 5, 2);
        add_filter('alttag_registrations_default_email_subject', [$this, 'filterDefaultSubject'], 5, 2);
        add_filter('alttag_registrations_email_display_qr_code', [$this, 'filterDisplayQrCode'], 6, 3);

        // Thank-you page overrides
        add_filter('alttag_registrations_checkout_heading', [$this, 'filterCheckoutHeading'], 6, 2);
        add_filter('alttag_registrations_checkout_greeting', [$this, 'filterCheckoutGreeting'], 6, 2);
        add_filter('alttag_registrations_checkout_show_event_details', [$this, 'filterShowEventDetails'], 6, 2);
        add_filter('alttag_registrations_checkout_show_qr_section', [$this, 'filterShowQrSection'], 6, 2);
        add_filter('alttag_registrations_display_ticket_download_link', [$this, 'filterDisplayTicketDownload'], 6, 2);
        add_action('alttag_registrations_checkout_after_download_links', [$this, 'renderThankYouBox'], 6);

        // Stripe receipt: hide "Pri vstupovaní na X" info text for recording orders.
        add_filter('alttag_registrations_receipt_show_info_text', [$this, 'filterReceiptShowInfoText'], 6, 2);

        // Riverstream webhook trigger for recording orders (parallel to LivestreamModule).
        add_action('alttag_registrations_participant_create', [$this, 'maybeGrantRecordingAccess'], 10, 2);
        add_action('alttag_registrations_participant_update', [$this, 'maybeGrantRecordingAccess'], 10, 2);
    }

    public function filterReceiptShowInfoText($show, $order)
    {
        return $this->orderHasRecording($order) ? false : $show;
    }

    /**
     * Fire the Riverstream access webhook for a recording purchase. Delegates
     * to LivestreamModule::sendRiverstreamWebhook with access_type='recording'.
     */
    public function maybeGrantRecordingAccess($participant_id, $order_data)
    {
        if (!is_livestream_enabled()) {
            return;
        }

        $context = ctx()->withParticipant($participant_id);
        $order = $context->order();
        if (!$order || !$this->orderHasRecording($order)) {
            return;
        }

        if ($order->get_status() !== 'completed') {
            return;
        }

        $should_grant = apply_filters(
            'alttag_registrations_should_grant_recording_access',
            true,
            $participant_id,
            $order_data
        );
        if (!$should_grant) {
            return;
        }

        $state = $context->participant();
        if ($state) {
            $state->setMeta('is_recording_user', '1');
        }

        $livestream = Core::getInstance()->moduleRegistry->get('livestream');
        if (!$livestream || !method_exists($livestream, 'sendRiverstreamWebhook')) {
            return;
        }

        $livestream->sendRiverstreamWebhook($context, 'recording', $order_data);
    }

    // =========================================================================
    // Recording detection
    // =========================================================================

    public function isRecordingProduct($product): bool
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

    public function orderHasRecording($order): bool
    {
        if (!$order instanceof \WC_Order) {
            return false;
        }

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $this->isRecordingProduct($product)) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // Email overrides
    // =========================================================================

    public function filterEmailTitle($title, $order, $participant_id = null)
    {
        if (!$this->orderHasRecording($order)) {
            return $title;
        }

        $template = $this->titleTemplate($order);
        return $template !== '' ? $this->renderProductTemplate($template, $order) : $title;
    }

    public function filterParticipantSubject($subject, $participant, $participant_id)
    {
        $context = ctx()->withParticipant($participant_id);
        $order = $context->order();

        if (!$this->orderHasRecording($order)) {
            return $subject;
        }

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
        if (!$this->orderHasRecording($order)) {
            return $content;
        }

        return ctx()->withOrder($order)->productName();
    }

    public function filterPaymentText($text, $order)
    {
        if (!$this->orderHasRecording($order)) {
            return $text;
        }

        $template = $this->setting('payment_confirmation_text', $order);
        return $template !== '' ? $this->renderProductTemplate($template, $order) : $text;
    }

    public function filterRegistrationText($text, $order)
    {
        if (!$this->orderHasRecording($order)) {
            return $text;
        }

        $template = $this->setting('registration_confirmation_text', $order);
        return $template !== '' ? $this->renderProductTemplate($template, $order) : $text;
    }

    public function filterDefaultSubject($subject, $order)
    {
        if (!$this->orderHasRecording($order)) {
            return $subject;
        }

        $template = $this->titleTemplate($order);
        if ($template === '') {
            return $subject;
        }

        $plain = str_replace('<br>', ' ', $template);
        return $this->renderProductTemplate($plain, $order, false);
    }

    public function filterDisplayQrCode($display, $order, $participant_id = null)
    {
        return $this->orderHasRecording($order) ? false : $display;
    }

    // =========================================================================
    // Thank-you page overrides
    // =========================================================================

    public function filterCheckoutHeading($heading, $order)
    {
        if (!$this->orderHasRecording($order)) {
            return $heading;
        }

        $key = $order->has_status('completed') ? 'checkout_heading_paid' : 'checkout_heading_unpaid';
        $template = (string) $this->setting($key, $order);
        return $template !== '' ? $this->renderProductTemplate($template, $order, false) : $heading;
    }

    public function filterCheckoutGreeting($greeting, $order)
    {
        if (!$this->orderHasRecording($order)) {
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
        return $this->orderHasRecording($order) ? false : $show;
    }

    public function filterShowQrSection($show, $order)
    {
        return $this->orderHasRecording($order) ? false : $show;
    }

    public function filterDisplayTicketDownload($display, $order)
    {
        return $this->orderHasRecording($order) ? false : $display;
    }

    public function renderThankYouBox($order)
    {
        if (!$this->orderHasRecording($order)) {
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
            if (!$product || !$this->isRecordingProduct($product)) {
                continue;
            }

            $title = $title_template !== ''
                ? str_replace('{product_name}', $item->get_name(), $title_template)
                : '';

            echo '<div class="alttag-recording-thankyou-box" style="' . esc_attr($box_style) . '">';
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
    // Helpers
    // =========================================================================

    private function setting($key, $order = null)
    {
        $product_id = $order instanceof \WC_Order
            ? ctx()->withOrder($order)->productId()
            : null;
        return Settings::getValue('recording.' . $key, $product_id);
    }

    private function titleTemplate($order): string
    {
        if (!$order instanceof \WC_Order) {
            return '';
        }

        $key = $order->get_status() === 'completed' ? 'email_title_paid' : 'email_title_unpaid';
        return (string) $this->setting($key, $order);
    }

    private function renderProductTemplate(string $template, $order, bool $escape = true): string
    {
        $product_name = ctx()->withOrder($order)->productName();
        if ($escape) {
            $product_name = esc_html($product_name);
        }
        return str_replace('{product_name}', $product_name, $template);
    }
}
