<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Branded "payment did not go through" e-mail for the buyer.
 *
 * WooCommerce's own failed-order e-mail is switched off site-wide
 * (WooCommerceManager::init, `woocommerce_email_enabled_failed_order`) and in
 * any case only ever reaches the shop admin, never the customer - WC has no
 * customer-facing failed-order template. So a buyer whose card is declined
 * would hear nothing at all: they are left on the thank-you page with the
 * default WooCommerce error notice and no way back if they close the tab.
 *
 * This sends them the retry link instead, rendered through the very same
 * wrapper as the participant confirmation e-mail
 * (EmailWrapper::convertToTableLayout + EmailWrapper::wrap), so it carries the
 * standard coloured header and the footer strip with contact address.
 *
 * i18n: strings are English source strings passed through __(), because the
 * plugin's shared catalogue (languages/alttag-registrations-*.po) is keyed by
 * the ENGLISH source string - the same convention as the rest of the core.
 * Site layers must NOT pass translated sentences to __(): a missing
 * translation would then silently return the sentence itself and disguise the
 * gap. Slovak wording lives in languages/alttag-registrations-sk_SK.po.
 */
class FailedOrderEmail
{
    /**
     * Order meta flag: this failure has already been e-mailed about.
     */
    const SENT_META = '_alttag_failed_email_sent';

    public function init()
    {
        add_action('woocommerce_order_status_failed', [$this, 'maybeSend'], 10, 2);
        add_action('woocommerce_order_status_completed', [$this, 'clearSentFlag'], 10, 2);
    }

    /**
     * Fire once per order, on the transition into `failed`.
     *
     * Gateways retry: a payment plugin can move an order pending -> failed more
     * than once for the same checkout (3-D Secure abandon, then a second
     * decline), and `woocommerce_order_status_failed` fires on each. The meta
     * flag keeps the buyer from collecting one e-mail per attempt; it is
     * cleared again as soon as the order is paid so a later, genuinely new
     * failure still notifies.
     *
     * @param int $order_id
     * @param \WC_Order|null $order
     */
    public function maybeSend($order_id, $order = null)
    {
        $order = $order instanceof \WC_Order ? $order : wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }

        if ($order->get_meta(self::SENT_META) === '1') {
            return;
        }

        $email = trim((string) $order->get_billing_email());
        if ($email === '' || !is_email($email)) {
            return;
        }

        if (!apply_filters('alttag_registrations_send_failed_email', true, $order)) {
            return;
        }

        $built = $this->buildEmail($order);
        if (!$built) {
            return;
        }

        $order->update_meta_data(self::SENT_META, '1');
        $order->save();

        wp_mail($email, $built['subject'], $built['html'], ['Content-Type: text/html; charset=UTF-8']);
    }

    /**
     * Let a retried order notify again if it fails a second time, days later.
     *
     * @param int $order_id
     * @param \WC_Order|null $order
     */
    public function clearSentFlag($order_id, $order = null)
    {
        $order = $order instanceof \WC_Order ? $order : wc_get_order($order_id);
        if ($order instanceof \WC_Order && $order->get_meta(self::SENT_META) !== '') {
            $order->delete_meta_data(self::SENT_META);
            $order->save();
        }
    }

    /**
     * Build subject + wrapped HTML for the failed-order e-mail.
     *
     * Wrapped in the plugin's `before/after_order_email` actions so
     * LanguageManager switches into the order's language for the duration,
     * exactly as WooCommerceManager::buildOrderEmail does for the confirmation.
     *
     * @param \WC_Order $order
     * @return array{subject:string,html:string}|null
     */
    public function buildEmail(\WC_Order $order)
    {
        do_action('alttag_registrations_before_order_email', $order);

        try {
            $template = $this->getFailedOrderTemplateForOrder($order);
            if ($template['subject'] === '' || $template['body'] === '') {
                return null;
            }

            // convertToTableLayout adds the coloured header (from the subject),
            // the content padding and the footer strip with contact address.
            $html = EmailWrapper::convertToTableLayout($template['body'], $template['subject']);
            $html = EmailWrapper::wrap($html, $template['subject']);

            return ['subject' => $template['subject'], 'html' => $html];
        } finally {
            do_action('alttag_registrations_after_order_email', $order);
        }
    }

    /**
     * Resolve the failed-order template for this order's product.
     *
     * Per-product selection works the same way as the confirmation e-mail's
     * (Settings::getEmailSubject): RegistrationContext::forOrder establishes
     * the product context from the order, so get_event_name_with_year() and
     * friends resolve against the order's product meta (`_event_name`) instead
     * of falling through to the global placeholder. A background send has no
     * cart context, so without this every subject would read "Event YYYY".
     *
     * Site layers override per product by hooking
     * `alttag_registrations_failed_order_email_template` and branching on the
     * order's product / event name; the array they return replaces the
     * built-in default below.
     *
     * @param \WC_Order $order
     * @return array{subject:string,body:string}
     */
    public function getFailedOrderTemplateForOrder(\WC_Order $order)
    {
        RegistrationContext::forOrder($order);

        try {
            $template = $this->getDefaultTemplate($order);
            $template = apply_filters(
                'alttag_registrations_failed_order_email_template',
                $template,
                $order
            );
        } finally {
            RegistrationContext::reset();
        }

        return [
            'subject' => (string) ($template['subject'] ?? ''),
            'body'    => (string) ($template['body'] ?? ''),
        ];
    }

    /**
     * Built-in fallback template: decline notice + retry CTA to the order-pay
     * URL.
     *
     * @param \WC_Order $order
     * @return array{subject:string,body:string}
     */
    private function getDefaultTemplate(\WC_Order $order)
    {
        $order_number = esc_html($order->get_order_number());

        $subject = sprintf(
            __('Payment for order no. %s was not successful', 'alttag-registrations'),
            $order_number
        );

        $paragraphs = [];

        $name = trim(
            trim((string) $order->get_billing_first_name())
            . ' ' . trim((string) $order->get_billing_last_name())
        );
        $paragraphs[] = $name !== ''
            ? sprintf(__('Dear %s,', 'alttag-registrations'), esc_html($name))
            : __('Hello,', 'alttag-registrations');

        $paragraphs[] = sprintf(
            __(
                'We could not process the payment for your order no. %s, so your registration is not complete yet.',
                'alttag-registrations'
            ),
            '<strong>' . $order_number . '</strong>'
        );

        $paragraphs[] = __(
            'The most common cause is a decline by your bank or an interrupted payment verification. '
            . 'No amount has been debited from your account.',
            'alttag-registrations'
        );

        $paragraphs[] = __(
            'You can retry the payment at any time using the link below. Your order stays saved, '
            . 'you do not need to enter it again.',
            'alttag-registrations'
        );

        $content = '<p>' . implode('</p><p>', $paragraphs) . '</p>'
            . $this->renderRetryButton($order->get_checkout_payment_url());

        return ['subject' => $subject, 'body' => $content];
    }

    /**
     * Retry CTA.
     *
     * Button as a table cell, not a styled <a>: Outlook drops padding on inline
     * anchors, which would render this as bare underlined text.
     *
     * @param string $pay_url
     * @return string
     */
    private function renderRetryButton($pay_url)
    {
        $primary = apply_filters('alttag_registrations_email_primary_color', '#2B5C63');
        $accent = apply_filters('alttag_registrations_email_accent_color', '#FFE500');

        return '<table border="0" cellpadding="0" cellspacing="0" style="margin: 0 auto;">'
            . '<tr><td align="center" style="background-color: ' . esc_attr($primary) . '; '
            . 'border-radius: 6px; padding: 14px 32px;">'
            . '<a href="' . esc_url($pay_url) . '" target="_blank" '
            . 'style="color: ' . esc_attr($accent) . '; font-family: Arial, Helvetica, sans-serif; '
            . 'font-size: 16px; font-weight: 700; text-decoration: none; display: inline-block;">'
            . esc_html__('Retry payment', 'alttag-registrations') . '</a>'
            . '</td></tr></table>';
    }
}
