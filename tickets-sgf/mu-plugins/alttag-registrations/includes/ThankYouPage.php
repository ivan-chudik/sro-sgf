<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Standard thank you page customizations.
 * Note: order_has_only_livestream_product filter is handled by CoreFilters.
 */
class ThankYouPage
{
    public function registerHooks()
    {
        add_filter('woocommerce_order_email_verification_required', [$this, 'skipOrderReceivedEmailVerification'], 10, 3);
        add_filter('alttag_registrations_checkout_greeting', [$this, 'customizeGreeting'], 5, 2);
        add_filter('alttag_registrations_display_ticket_download_link', [$this, 'controlTicketDownload'], 5, 2);
        add_action('alttag_registrations_checkout_after_download_links', [$this, 'displaySelectedDays']);
    }

    /**
     * Keep key-authenticated registration thank-you links directly viewable.
     *
     * WooCommerce has already matched both the order ID and its secret order
     * key before this filter runs. Newer WooCommerce versions additionally ask
     * guests to re-enter their billing email after a short grace period, which
     * prevents the registration thank-you template from rendering. Do not
     * change verification for order payment or any other order context.
     */
    public function skipOrderReceivedEmailVerification($required, $order, $context)
    {
        if ($context === 'order-received') {
            return false;
        }

        return $required;
    }

    /**
     * Customize greeting with participant name
     */
    public function customizeGreeting($greeting, $order)
    {
        $context = ctx()->withOrder($order);
        $full_name = $context->participantName();

        if (empty($full_name)) {
            return $greeting;
        }

        return sprintf(
            __('Dear %s,', 'alttag-registrations'),
            esc_html($full_name)
        );
    }

    /**
     * Control ticket download link based on product type
     */
    public function controlTicketDownload($display, $order)
    {
        return ctx()->withOrder($order)->shouldShowTicket();
    }

    /**
     * Display selected days on thank you page
     */
    public function displaySelectedDays($order)
    {
        if (!$order instanceof \WC_Order) {
            return;
        }

        $selected_days = ctx()->withOrder($order)->selectedDaysSummary();
        $selected_days = apply_filters('alttag_registrations_thankyou_selected_days', $selected_days, $order);
        if ($selected_days === '') {
            return;
        }

        echo '<p><strong>'
            . esc_html__('Selected days', 'alttag-registrations')
            . ':</strong> ' . esc_html($selected_days) . '</p>';
    }
}
