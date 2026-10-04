<?php

namespace Alttag\Registrations;

use Alttag\Registrations\Selection\SelectionManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single entry point for all application state.
 *
 * Everything is accessible from ctx():
 *
 *   ctx()->product()->isLivestream()
 *   ctx()->product()->eventName()
 *   ctx()->product()->availableDates()
 *   ctx()->participant()->selectedDays()
 *   ctx()->participant()->order()
 *   ctx()->cart()->hasLivestream
 *   ctx()->isMultilingual()
 *   ctx()->hasInvoicing()
 *   ctx()->orgName()
 *
 * Context is auto-detected. Can be set explicitly:
 *   RegistrationContext::forProduct($id)
 *   RegistrationContext::forParticipant($id)
 *   RegistrationContext::forOrder($order)
 */
class RegistrationContext
{
    /** @var self|null */
    private static $current = null;

    /** @var int|null */
    private $product_id = null;

    /** @var ProductConfig|null|false */
    private $product_config = false;

    /** @var ParticipantState|null|false */
    private $participant_state = false;

    /** @var \WC_Order|null|false */
    private $order = false;

    /** @var object|null */
    private $cart_state = null;

    /** @var Settings */
    private $settings;

    // =========================================================================
    // Construction
    // =========================================================================

    private function __construct($product_id = null)
    {
        $this->product_id = $product_id;
        $this->settings = Core::getInstance()->settings;
    }

    public static function current()
    {
        if (self::$current === null) {
            // Publish the instance BEFORE resolving the product id: a
            // re-entrant call made while the cart/order is being read
            // (e.g. a gettext filter fired from a WooCommerce notice) must
            // see this context instead of recursing into
            // getCurrentContextProductId() forever (OOM). product_id stays
            // null until resolution finishes; late callers read the
            // resolved value, re-entrant ones at least stop looping.
            $ctx = new self(null);
            self::$current = $ctx;
            $product_id = EventManager::getCurrentContextProductId();
            if ($product_id) {
                $ctx->setProductId((int) $product_id);
            }
        }
        return self::$current;
    }

    /**
     * Get the current context instance without triggering initialization.
     * Used by EventManager::getCurrentContextProductId() to avoid infinite loop.
     */
    public static function currentOrNull()
    {
        return self::$current;
    }

    public static function forProduct($product_id)
    {
        $ctx = new self();
        $ctx->setProduct($product_id);
        self::$current = $ctx;
        return $ctx;
    }

    public static function forParticipant($participant_id)
    {
        $ctx = new self();
        $ctx->setParticipant($participant_id);
        self::$current = $ctx;
        return $ctx;
    }

    public static function forOrder($order)
    {
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }

        $ctx = new self();
        $ctx->setOrder($order);
        self::$current = $ctx;
        return $ctx;
    }

    public static function reset()
    {
        self::$current = null;
    }

    public function withProduct($product_id)
    {
        $ctx = clone $this;
        return $ctx->setProduct($product_id);
    }

    public function withParticipant($participant_id)
    {
        $ctx = clone $this;
        return $ctx->setParticipant($participant_id);
    }

    public function withOrder($order)
    {
        $ctx = clone $this;
        $ctx->setOrder($order);
        self::$current = $ctx;
        return $ctx;
    }

    private function setProductId($product_id)
    {
        $product_id = $product_id ? (int) $product_id : null;
        if ($this->product_id !== $product_id) {
            $this->product_id = $product_id;
            $this->product_config = false;
        }
    }

    private function resolveProductIdFromOrder($order)
    {
        if (!$order instanceof \WC_Order) {
            return null;
        }

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                return (int) $product->get_id();
            }
        }

        return null;
    }

    private function resolveProductIdFromParticipant($state)
    {
        if (!$state instanceof ParticipantState) {
            return null;
        }

        $product_id = (int) $state->getMeta('product_id');
        if ($product_id) {
            return $product_id;
        }

        return $this->resolveProductIdFromOrder($state->order());
    }

    // =========================================================================
    // Product (returns ProductConfig)
    // =========================================================================

    /**
     * Get ProductConfig for current context.
     * This is the primary way to access product settings.
     *
     *   ctx()->product()->isLivestream()
     *   ctx()->product()->eventName()
     *   ctx()->product()->maxParticipants()
     *   ctx()->product()->availableDates()
     *   ctx()->product()->sku()
     *
     * @return ProductConfig|null
     */
    public function product()
    {
        if ($this->product_config === false) {
            $this->product_config = $this->product_id
                ? ProductConfig::get($this->product_id)
                : null;
        }
        return $this->product_config;
    }

    /**
     * Shortcut: product ID
     */
    public function productId()
    {
        return $this->product_id;
    }

    public function productName()
    {
        $product = $this->product();
        return $product ? $product->name() : '';
    }

    public function productSku()
    {
        $product = $this->product();
        return $product ? $product->sku() : '';
    }

    public function setProduct($product_id)
    {
        $this->participant_state = false;
        $this->order = false;
        $this->setProductId($product_id);
        return $this;
    }

    // =========================================================================
    // Participant (returns ParticipantState)
    // =========================================================================

    /**
     * Get ParticipantState for current context.
     *
     *   ctx()->participant()->selectedDays()
     *   ctx()->participant()->isLivestream()
     *   ctx()->participant()->getFullName()
     *   ctx()->participant()->order()
     *
     * @return ParticipantState|null
     */
    public function participant()
    {
        if ($this->participant_state === false) {
            $this->participant_state = null;

            $order = $this->order();
            if ($order) {
                $p = get_participant_by_order_id($order->get_id());
                if ($p) {
                    $this->participant_state = ParticipantState::get($p->ID);
                }
            }

            if ($this->participant_state) {
                $this->setProductId($this->resolveProductIdFromParticipant($this->participant_state));
            }
        }
        return $this->participant_state;
    }

    /**
     * Set participant explicitly
     */
    public function setParticipant($participant_id)
    {
        $this->participant_state = ParticipantState::get($participant_id);
        $this->order = $this->participant_state ? ($this->participant_state->order() ?: null) : null;
        $this->setProductId($this->resolveProductIdFromParticipant($this->participant_state));
        return $this;
    }

    public function participantId()
    {
        $participant = $this->participant();
        return $participant ? $participant->id : null;
    }

    public function participantName()
    {
        $participant = $this->participant();
        return $participant ? $participant->getFullName() : '';
    }

    // =========================================================================
    // Order
    // =========================================================================

    public function order()
    {
        if ($this->order === false) {
            $this->order = null;

            if ($this->participant_state instanceof ParticipantState) {
                $this->order = $this->participant_state->order() ?: null;
            }

            global $wp;
            if (!$this->order && function_exists('WC') && !empty($wp->query_vars['order-received'])) {
                $this->order = wc_get_order($wp->query_vars['order-received']);
            }

            if ($this->order) {
                $this->setProductId($this->resolveProductIdFromOrder($this->order));
            }
        }
        return $this->order;
    }

    public function setOrder($order)
    {
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }
        $this->order = $order ?: null;
        $this->participant_state = false;
        $this->setProductId($this->resolveProductIdFromOrder($this->order));
        return $this;
    }

    public function orderId()
    {
        $order = $this->order();
        return $order ? $order->get_id() : null;
    }

    // =========================================================================
    // Cart
    // =========================================================================

    /**
     * Cart state with pre-computed flags.
     *
     *   ctx()->cart()->hasLivestream
     *   ctx()->cart()->hasInperson
     *   ctx()->cart()->hasOnlyLivestream
     *   ctx()->cart()->productIds
     *   ctx()->cart()->isEmpty
     */
    public function cart()
    {
        if ($this->cart_state === null) {
            $product_ids = get_cart_product_ids();
            $has_livestream = false;
            $has_inperson = false;

            foreach ($product_ids as $pid) {
                if (product_is_livestream($pid)) {
                    $has_livestream = true;
                } else {
                    $has_inperson = true;
                }
            }

            $this->cart_state = (object) [
                'productIds' => $product_ids,
                'isEmpty' => empty($product_ids),
                'hasLivestream' => $has_livestream,
                'hasInperson' => $has_inperson,
                'hasOnlyLivestream' => $has_livestream && !$has_inperson,
                'hasOnlyInperson' => $has_inperson && !$has_livestream,
                'hasMixed' => $has_livestream && $has_inperson,
            ];
        }
        return $this->cart_state;
    }

    // =========================================================================
    // Plugin / Feature Detection
    // =========================================================================

    public function isMultilingual()
    {
        return function_exists('pll_languages_list')
            && count(pll_languages_list()) > 1;
    }

    public function hasPolylang()
    {
        return function_exists('pll_current_language');
    }

    public function hasInvoicing()
    {
        return is_plugin_active('woocommerce-superfaktura/woocommerce-superfaktura.php');
    }

    public function hasStripe()
    {
        return class_exists('WC_Stripe_Payment_Gateway')
            || class_exists('WC_Stripe_Gateway')
            || is_plugin_active('woocommerce-gateway-stripe/woocommerce-gateway-stripe.php')
            || is_plugin_active('woo-stripe-payment/stripe-payments.php');
    }

    public function hasWooCommerce()
    {
        return function_exists('WC');
    }

    // =========================================================================
    // Language
    // =========================================================================

    public function languages()
    {
        return Settings::getAvailableLanguages();
    }

    public function defaultLanguage()
    {
        return get_default_language();
    }

    public function currentLanguage()
    {
        return get_current_language();
    }

    public function orderLanguage()
    {
        $order = $this->order();
        if (!$order) {
            return $this->defaultLanguage();
        }

        $language = $order->get_meta('_language');
        return $language ?: $this->defaultLanguage();
    }

    public function participantLanguage()
    {
        $participant = $this->participant();
        if ($participant) {
            return $participant->language();
        }

        return $this->orderLanguage();
    }

    public function language()
    {
        $participant = $this->participant();
        if ($participant) {
            return $this->participantLanguage();
        }

        if ($this->order()) {
            return $this->orderLanguage();
        }

        return $this->currentLanguage();
    }

    // =========================================================================
    // Livestream (global flags, not per-product)
    // =========================================================================

    public function isLivestreamEnabled()
    {
        return is_livestream_enabled();
    }

    public function streamUrl()
    {
        return get_stream_url();
    }

    public function streamLinkText()
    {
        return get_stream_link_text();
    }

    // =========================================================================
    // Organization / Branding
    // =========================================================================

    public function orgName()
    {
        return get_event_organization_name();
    }

    public function teamName($order = null)
    {
        return get_event_organization_team_name($order);
    }

    public function contactEmail()
    {
        return get_event_organization_contact_email();
    }

    public function mailFrom()
    {
        return get_receipt_mail_from();
    }

    // =========================================================================
    // Email / Receipt
    // =========================================================================

    public function primaryColor()
    {
        return apply_filters('alttag_registrations_email_primary_color', '#323232');
    }

    public function accentColor()
    {
        return apply_filters('alttag_registrations_email_accent_color', '#FFFFFF');
    }

    public function backgroundColor()
    {
        return apply_filters('alttag_registrations_email_background_color', '#f5f5f5');
    }

    public function darkPrimaryColor()
    {
        return apply_filters('alttag_registrations_email_dark_primary_color', '#000000');
    }

    public function receiptTitle()
    {
        return get_receipt_title($this->order());
    }

    public function receiptProductTitle($order = null)
    {
        $order = $order instanceof \WC_Order ? $order : $this->order();
        if (!$order instanceof \WC_Order) {
            return '';
        }

        $product_titles = [];

        foreach ($order->get_items() as $item) {
            if ($item->get_type() !== 'line_item') {
                continue;
            }

            $product_titles[] = $item->get_name();
        }

        return !empty($product_titles) ? implode(', ', $product_titles) : '';
    }

    public function receiptSubject($order = null)
    {
        return get_receipt_subject($order);
    }

    public function infoText()
    {
        return get_receipt_info_text();
    }

    public function footerInfoText()
    {
        $lang = $this->language();
        $text = Settings::getTranslatableWithLang('email.footer_info_text', $lang);
        if (!empty($text)) {
            $text = str_replace('{contact_email}', $this->contactEmail(), $text);
        }

        // Filtered here rather than at each call site so one hook covers every
        // footer we render: the order e-mail template and the Stripe receipt
        // (which reads this value through localizedData()). $this is passed so
        // a listener can resolve the order's language instead of the request's.
        return (string) apply_filters('alttag_registrations_footer_info_text', $text, $this);
    }

    // =========================================================================
    // Event (delegates to product or filters)
    // =========================================================================

    public function eventName()
    {
        $pc = $this->product();
        $product_id = $pc ? $pc->product()->get_id() : null;
        $value = Settings::getValue('general.event_name', $product_id);
        return $value !== '' ? $value : get_event_name();
    }

    public function eventNameLocative()
    {
        $pc = $this->product();
        $product_id = $pc ? $pc->product()->get_id() : null;
        $value = Settings::getValue('general.event_name_locative', $product_id);
        return $value !== '' ? $value : $this->eventName();
    }

    public function eventNameAccusative()
    {
        $pc = $this->product();
        $product_id = $pc ? $pc->product()->get_id() : null;
        $value = Settings::getValue('general.event_name_accusative', $product_id);
        return $value !== '' ? $value : $this->eventName();
    }

    public function eventNoun()
    {
        return \Alttag\Registrations\get_event_noun();
    }

    public function eventNounGenitive()
    {
        return \Alttag\Registrations\get_event_noun_genitive();
    }

    public function eventNameWithYear()
    {
        return get_event_name_with_year();
    }

    public function eventNameWithDate()
    {
        return get_event_name_with_date();
    }

    public function productEventNameWithDate()
    {
        $product = $this->product();
        if (!$product) {
            return $this->eventNameWithDate();
        }

        $name = $product->eventName();
        $dates = $product->eventDatesString();

        if ($name === '') {
            return $dates;
        }

        if ($dates === '') {
            return $name;
        }

        return $name . ' - ' . $dates;
    }

    public function eventDates()
    {
        return get_event_dates();
    }

    public function eventDatesString()
    {
        $pc = $this->product();
        return $pc ? $pc->eventDatesString() : get_event_dates_string();
    }

    public function eventLocation($case = 'nominative')
    {
        $pc = $this->product();
        $product_id = $pc ? $pc->product()->get_id() : null;

        if ($case === 'locative') {
            $locative = Settings::getValue('general.venue_locative', $product_id);
            if ($locative !== '') {
                return $locative;
            }
        }

        $venue = Settings::getValue('general.venue', $product_id);
        return $venue !== '' ? $venue : get_event_location($case);
    }

    public function eventLocationWithStreet($case = 'nominative')
    {
        $pc = $this->product();
        $product_id = $pc ? $pc->product()->get_id() : null;

        if ($case === 'locative') {
            $locative = Settings::getValue('general.venue_locative', $product_id);
            if ($locative !== '') {
                return $locative;
            }
        }

        $venue = Settings::getValue('general.venue', $product_id);
        $address = Settings::getValue('general.venue_address', $product_id);

        if ($venue !== '' && $address !== '') {
            return $venue . ', ' . $address;
        }
        return $venue !== '' ? $venue : get_event_location_with_street($case);
    }

    public function eventMapUrl()
    {
        $pc = $this->product();
        $product_id = $pc ? $pc->product()->get_id() : null;

        return esc_url_raw(Settings::getValue('general.event_map_url', $product_id));
    }

    public function eventLocationWithMap($case = 'nominative')
    {
        $location = $this->eventLocationWithStreet($case);
        $map_url = $this->eventMapUrl();

        if ($location === '' || $map_url === '') {
            return esc_html($location);
        }

        return sprintf(
            '%1$s (<a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>)',
            esc_html($location),
            esc_url($map_url),
            esc_html__('on the map', 'alttag-registrations')
        );
    }

    // =========================================================================
    // Checkout / Thank-you helpers
    // =========================================================================

    public function thankYouEventDetailsText()
    {
        if ($this->isLivestreamProduct()) {
            return sprintf(
                /* translators: 1: Event name, 2: Event date */
                __('Thank you for registering for the livestream of %1$s on %2$s.', 'alttag-registrations'),
                '<strong>' . esc_html($this->eventName()) . '</strong>',
                '<strong>' . esc_html($this->eventDatesString()) . '</strong>'
            );
        }

        $location = $this->eventLocationWithMap('locative');
        if (!empty($location)) {
            return sprintf(
                /* translators: 1: Event name, 2: Event date, 3: Event location (locative) */
                __(
                    'see you at the event %1$s, which will take place on %2$s at %3$s',
                    'alttag-registrations'
                ),
                '<strong>' . esc_html($this->eventName()) . '</strong>',
                '<strong>' . esc_html($this->eventDatesString()) . '</strong>',
                '<strong>' . $location . '</strong>'
            ) . '.';
        }

        return sprintf(
            /* translators: 1: Event name, 2: Event date */
            __('see you at the event %1$s on %2$s', 'alttag-registrations'),
            '<strong>' . esc_html($this->eventName()) . '</strong>',
            '<strong>' . esc_html($this->eventDatesString()) . '</strong>'
        ) . '.';
    }

    public function thankYouHasInvoice($order = null)
    {
        if (!$order instanceof \WC_Order || $order->get_total() <= 0) {
            return false;
        }

        $has_invoice = !empty($order->get_meta('wc_sf_invoice_regular'))
            && apply_filters('alttag_registrations_display_invoice_download_link', true, $order);

        return (bool) apply_filters('alttag_registrations_checkout_has_invoice', $has_invoice, $order, $this);
    }

    public function thankYouHasReceipt($order = null)
    {
        if (!$order instanceof \WC_Order) {
            return false;
        }

        $has_receipt = $this->thankYouHasInvoice($order)
            && $order->has_status('completed')
            && str_starts_with($order->get_payment_method(), 'stripe');

        return (bool) apply_filters('alttag_registrations_checkout_has_receipt', $has_receipt, $order, $this);
    }

    public function thankYouConfirmationMessage($order = null)
    {
        if (!$order instanceof \WC_Order) {
            return __('We are sending the detailed information to your email.<br>Please check your spam folder as well.', 'alttag-registrations');
        }

        if ($this->thankYouHasReceipt($order)) {
            return __('We are sending the invoice and the receipt with detailed information to your email.<br>Please check your spam folder as well.', 'alttag-registrations');
        }

        if ($this->thankYouHasInvoice($order)) {
            return __('We are sending the invoice and detailed information to your email.<br>Please check your spam folder as well.', 'alttag-registrations');
        }

        return __('We are sending the detailed information to your email.<br>Please check your spam folder as well.', 'alttag-registrations');
    }

    public function emailEventString()
    {
        $event_name = $this->eventName();
        $event_dates = $this->eventDatesString();

        if ($event_name === '') {
            return $event_dates;
        }

        if ($event_dates === '') {
            return $event_name;
        }

        return sprintf('%1$s - %2$s', $event_name, $event_dates);
    }

    public function isLivestreamEmailTarget()
    {
        $participant = $this->participant();
        if ($participant) {
            return $participant->isLivestream();
        }

        $order = $this->order();
        if ($order instanceof \WC_Order) {
            return order_has_only_livestream_product($order);
        }

        return $this->isLivestreamProduct();
    }

    public function emailTitleText($use_break = true)
    {
        $separator = $use_break ? '<br>' : ' ';

        if ($this->isLivestreamEmailTarget()) {
            return sprintf(
                __('Registration confirmation for%1$s%2$s', 'alttag-registrations'),
                $separator,
                $this->emailEventString()
            );
        }

        return sprintf(
            __('Ticket for%1$s%2$s', 'alttag-registrations'),
            $separator,
            $this->emailEventString()
        );
    }

    public function emailGreeting($fallback = '')
    {
        $participant = $this->participant();

        $first_name = '';
        $last_name = '';
        $titles_before = '';
        $titles_after = '';

        if ($participant) {
            $pid = $participant->id;
            $first_name = (string) get_post_meta($pid, 'first_name', true);
            $last_name = (string) get_post_meta($pid, 'last_name', true);
            $titles_before = (string) get_post_meta($pid, 'titles_before_name', true);
            $titles_after = (string) get_post_meta($pid, 'titles_after_name', true);
        }

        $name_parts = array_filter([$titles_before, $first_name, $last_name]);
        $full_name = implode(' ', $name_parts);
        if ($titles_after !== '' && $full_name !== '') {
            $full_name .= ', ' . $titles_after;
        }

        if ($full_name === '') {
            $full_name = $this->participantName();
        }

        if ($full_name === '') {
            return $fallback;
        }

        $template = Settings::getValue('email.greeting_format', $this->productId());
        if ($template !== '') {
            $replaced = strtr($template, [
                '{full_name}' => $full_name,
                '{first_name}' => $first_name,
                '{last_name}' => $last_name,
                '{titles_before}' => $titles_before,
                '{titles_after}' => $titles_after,
            ]);
            return esc_html($replaced);
        }

        return sprintf(
            __('Dear %s,', 'alttag-registrations'),
            esc_html($full_name)
        );
    }

    public function emailEventDetails()
    {
        $event_name = $this->eventName();
        $event_dates = $this->eventDatesString();

        if ($event_name === '' || $event_dates === '') {
            return '';
        }

        $event_location = $this->eventLocationWithMap('locative');

        if ($event_location === '') {
            return sprintf(
                __('%1$s, which will take place on %2$s', 'alttag-registrations'),
                $event_name,
                $event_dates
            );
        }

        return sprintf(
            __('%1$s, which will take place on %2$s at %3$s', 'alttag-registrations'),
            $event_name,
            $event_dates,
            $event_location
        );
    }

    public function shouldDisplayEmailQrCode()
    {
        $participant = $this->participant();
        if ($participant) {
            return $participant->shouldShowQrCode();
        }

        $order = $this->order();
        if (!$order instanceof \WC_Order) {
            return true;
        }

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $config = ProductConfig::get($product->get_id());
            if ($config && !$config->showsQrCode()) {
                return false;
            }
        }

        return true;
    }

    public function hasLivestreamEmailInfo()
    {
        $participant = $this->participant();
        if ($participant) {
            return $participant->isLivestream();
        }

        return $this->orderHasLivestream();
    }

    /**
     * Should we render the in-person "show QR at venue" block in the email?
     *
     * Mirrors {@see hasLivestreamEmailInfo}: an order can contain BOTH modes
     * (separate products, or one product with per-day modes), so this is an
     * independent check — not the negation of the livestream variant.
     */
    public function hasInpersonEmailInfo()
    {
        return $this->orderHasInperson();
    }

    public function emailLivestreamInfoTexts()
    {
        $stream_url = trim((string) $this->streamUrl());

        // `alttag_registrations_stream_url` is unset on most sites, so the
        // linked wording would splice an empty <a href=""></a> into the middle
        // of the sentence. Use the link-free variant whenever there is no URL
        // to point at.
        if ($stream_url === '') {
            $login = __('An email to create your password will be sent to you. After creating it, you will be able to log in with your email and created password to watch the livestream.', 'alttag-registrations');
        } else {
            $login = sprintf(
                __('An email to create your password will be sent to you. After creating it, you will be able to log in to %1$s with your email and created password to watch the livestream.', 'alttag-registrations'),
                '<a href="' . esc_url($stream_url) . '" target="_blank">'
                    . esc_html($this->streamLinkText()) . '</a>'
            );
        }

        return [
            'login' => $login,
            'device' => __(
                'You can only watch the livestream from one device. If you log in from another device, you will be automatically logged out and will need to log in again.',
                'alttag-registrations'
            ),
        ];
    }

    /**
     * Wording for the in-person paragraph in the email. Mirrors the
     * thank-you page's QR paragraph so both surfaces stay in sync.
     */
    public function emailInpersonInfoTexts()
    {
        return [
            'qr_at_venue' => sprintf(
                __(
                    'When entering %1$s on %2$s at %3$s, please show your QR code from the email.',
                    'alttag-registrations'
                ),
                '<strong>' . esc_html($this->eventNameAccusative()) . '</strong>',
                '<strong>' . esc_html($this->eventDatesString()) . '</strong>',
                '<strong>' . $this->eventLocationWithMap('locative') . '</strong>'
            ),
        ];
    }

    // =========================================================================
    // Convenience delegations (so ctx() works without ->product())
    // =========================================================================

    /**
     * Is current product multi-day?
     * Shortcut for ctx()->product()->isMultiDay()
     */
    public function isMultiDay()
    {
        $pc = $this->product();
        return $pc ? $pc->isMultiDay() : false;
    }

    /**
     * Is current product livestream?
     * Shortcut for ctx()->product()->isLivestream()
     */
    public function isLivestreamProduct()
    {
        $pc = $this->product();
        return $pc ? $pc->isLivestream() : false;
    }

    /**
     * Is current product in-person?
     */
    public function isInpersonProduct()
    {
        $pc = $this->product();
        return $pc ? $pc->isInperson() : true;
    }

    /**
     * Does the order contain any livestream products?
     *
     * The product-level check covers the simple case where a separate SKU
     * represents the online attendance. Sites that store attendance mode
     * per-line-item (e.g. a single product whose day-by-day mode is recorded
     * in order item meta) can extend the result via the filter.
     */
    public function orderHasLivestream(): bool
    {
        $order = $this->order();
        if (!$order instanceof \WC_Order) {
            $value = $this->isLivestreamProduct();
            return (bool) apply_filters('alttag_registrations_order_has_livestream', $value, null, $this);
        }
        $value = false;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && product_is_livestream($product->get_id())) {
                $value = true;
                break;
            }
        }
        return (bool) apply_filters('alttag_registrations_order_has_livestream', $value, $order, $this);
    }

    /**
     * Does the order contain any in-person products?
     *
     * See {@see orderHasLivestream} for the per-line-item extension pattern.
     */
    public function orderHasInperson(): bool
    {
        $order = $this->order();
        if (!$order instanceof \WC_Order) {
            $value = $this->isInpersonProduct();
            return (bool) apply_filters('alttag_registrations_order_has_inperson', $value, null, $this);
        }
        $value = false;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && !product_is_livestream($product->get_id())) {
                $value = true;
                break;
            }
        }
        return (bool) apply_filters('alttag_registrations_order_has_inperson', $value, $order, $this);
    }

    /**
     * Available dates shortcut
     */
    public function availableDates()
    {
        $pc = $this->product();
        return $pc ? $pc->availableDates() : get_registrations_available_dates();
    }

    public function availableDateLabels()
    {
        // If participant has a different language than the product, try translated product labels
        $participant = $this->participant();
        if ($participant && function_exists('pll_get_post')) {
            $lang = $participant->language();
            $pc = $this->product();
            $product = $pc ? $pc->product() : null;
            if ($product && $lang) {
                $product_id = $product->get_id();
                $translated_id = pll_get_post($product_id, $lang);
                if ($translated_id && $translated_id !== $product_id) {
                    $translated_pc = ProductConfig::get($translated_id);
                    if ($translated_pc) {
                        return $translated_pc->availableDates();
                    }
                }
            }
        }
        return $this->availableDates();
    }

    /**
     * Active selections shortcut
     */
    public function activeSelections()
    {
        $pc = $this->product();
        return $pc ? $pc->activeSelections() : [];
    }

    public function hasSelections()
    {
        return !empty($this->activeSelections());
    }

    public function selection($typeId)
    {
        return SelectionManager::getType($typeId);
    }

    public function selectedDays()
    {
        $participant = $this->participant();
        if ($participant) {
            $days = $participant->selectedDays();
            if (!empty($days)) {
                return $days;
            }
        }

        $order = $this->order();
        if (!$order) {
            return [];
        }

        foreach ($order->get_items() as $item) {
            $days = $item->get_meta('selected_days');
            if (is_array($days) && !empty($days)) {
                return $days;
            }
        }

        return [];
    }

    public function selectedDaysData()
    {
        $participant = $this->participant();
        if ($participant) {
            $data = $participant->selectedDaysData();
            if (!empty($data)) {
                return $data;
            }
        }

        $order = $this->order();
        if (!$order) {
            return [];
        }

        foreach ($order->get_items() as $item) {
            $data = $item->get_meta('selected_days_data');
            if (is_array($data) && !empty($data)) {
                return $data;
            }
        }

        return [];
    }

    public function formatSelectedDays(array $days)
    {
        if (empty($days)) {
            return '';
        }

        $labels = $this->availableDateLabels();
        $parts = array_map(function ($date) use ($labels) {
            return $labels[$date] ?? $date;
        }, $days);

        return implode(', ', $parts);
    }

    public function formatSelectedDaysData(array $days_data)
    {
        if (empty($days_data)) {
            return '';
        }

        $labels = $this->availableDateLabels();
        $has_multi = false;

        foreach ($days_data as $count) {
            if ((int) $count > 1) {
                $has_multi = true;
                break;
            }
        }

        $parts = [];
        foreach ($days_data as $date => $count) {
            $label = $labels[$date] ?? $date;

            if ($has_multi) {
                $count = (int) $count;
                $persons = sprintf(_n('%d person', '%d persons', $count, 'alttag-registrations'), $count);
                $parts[] = $label . ' (' . $persons . ')';
                continue;
            }

            $parts[] = $label;
        }

        return implode(', ', $parts);
    }

    public function selectedDaysSummary()
    {
        $days_data = $this->selectedDaysData();
        if (!empty($days_data)) {
            return $this->formatSelectedDaysData($days_data);
        }

        $days = $this->selectedDays();
        return !empty($days) ? $this->formatSelectedDays($days) : '';
    }

    public function isLivestreamParticipant()
    {
        $participant = $this->participant();
        if ($participant) {
            return $participant->isLivestream();
        }

        return $this->isLivestreamProduct();
    }

    public function isInpersonParticipant()
    {
        return !$this->isLivestreamParticipant();
    }

    public function shouldShowTicket()
    {
        $participant = $this->participant();
        if ($participant) {
            return $participant->shouldGetTicket();
        }

        $order = $this->order();
        if ($order instanceof \WC_Order) {
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if ($product && product_generates_ticket($product->get_id())) {
                    return true;
                }
            }
            return false;
        }

        $product = $this->product();
        return $product ? $product->generatesTicket() : false;
    }

    public function variableSymbolPrefix($default = 'FREE')
    {
        // 1. Try per-product or global setting
        $pc = $this->product();
        $product_id = $pc ? $pc->product()->get_id() : null;
        $configured = Settings::getValue('general.variable_symbol_prefix', $product_id);
        if ($configured !== '') {
            return $configured;
        }

        // 2. Fallback: generate from SKU
        $sku = $this->productSku();
        if ($sku === '') {
            return $default;
        }

        $parts = explode('-', $sku);
        if (count($parts) >= 3) {
            $event = strtoupper($parts[0]);
            $year = $parts[1];
            $type = strtoupper(substr($parts[2], 0, 2));
            return $event . $year . $type;
        }

        return strtoupper(str_replace('-', '', $sku));
    }

    public function superfakturaLanguageCode($default = '')
    {
        $map = [
            'sk' => 'slo',
            'cs' => 'cze',
            'en' => 'eng',
            'de' => 'deu',
            'nl' => 'nld',
            'hr' => 'hrv',
            'hu' => 'hun',
            'pl' => 'pol',
            'ro' => 'rom',
            'ru' => 'rus',
            'sl' => 'slv',
            'es' => 'spa',
            'it' => 'ita',
            'uk' => 'ukr',
        ];

        return $map[$this->language()] ?? $default;
    }

    public function receiptData()
    {
        $product = $this->product();

        return [
            'receipt_from' => $this->receiptTitle(),
            'event_name_with_date' => $this->productEventNameWithDate(),
            'event_name_with_year' => $product ? $product->eventNameWithYear() : $this->eventNameWithYear(),
            'mail_from' => $this->mailFrom(),
            'footer_info_text' => $this->footerInfoText(),
            'info_text' => $this->infoText(),
        ];
    }

    // =========================================================================
    // Settings
    // =========================================================================

    public function setting($key, $default = '')
    {
        return $this->settings->get($key, $default);
    }
}
