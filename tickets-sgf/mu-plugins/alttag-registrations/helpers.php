<?php

namespace Alttag\Registrations;

use \WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get the participant manager
 *
 * @return \Alttag\Registrations\ParticipantManager The participant manager
 */
function get_participant_manager()
{
    return new \Alttag\Registrations\ParticipantManager();
}

/**
 * Get the participant by order ID
 *
 * @param int $order_id The order ID
 * @return \Alttag\Registrations\Participant|null The participant or null if not found
 */
function get_participant_by_order_id($order_id)
{
    $participantManager = get_participant_manager();
    $participant = $participantManager->getParticipantByOrderId($order_id);

    if (empty($participant)) {
        return null;
    }

    return $participant;
}

/**
 * Get the participant by variable symbol
 *
 * @param string $variable_symbol The variable symbol
 * @return \WP_Post|null The participant post or null if not found
 */
function get_participant_by_variable_symbol($variable_symbol)
{
    $participantManager = get_participant_manager();
    $participant = $participantManager->getParticipantByVariableSymbol($variable_symbol);

    if (empty($participant)) {
        return null;
    }

    return $participant;
}

/**
 * Get the participant variable symbol
 *
 * @param int $participant_id The participant ID
 * @return string|null The participant variable symbol or null if not found
 */
function get_participant_variable_symbol($participant_id)
{
    $variable_symbol = get_post_meta($participant_id, 'variable_symbol', true);

    if (empty($variable_symbol)) {
        return null;
    }

    return $variable_symbol;
}


/**
 * Get the event location
 *
 * @param string $case Grammatical case for declension (e.g., 'locative' for Slovak 6th case)
 * @return string The event location
 */
function get_event_location($case = 'nominative')
{
    return apply_filters('alttag_registrations_event_location', '', $case);
}

/**
 * Get the event location with street
 *
 * @param string $case Grammatical case for declension (e.g., 'locative' for Slovak 6th case)
 * @return string The event location with street
 */
function get_event_location_with_street($case = 'nominative')
{
    return apply_filters('alttag_registrations_event_location_with_street', '', $case);
}


/**
 * Get the event date
 *
 * @return array The event date
 */
function get_event_dates()
{
    return apply_filters('alttag_registrations_event_dates', []);
}

/**
 * Get the event dates string
 *
 * @return string The event dates string
 */
function get_event_dates_string()
{
    return apply_filters('alttag_registrations_event_dates_string', '');
}

/**
 * Get the event time
 *
 * @return string The event time
 */
function get_event_time()
{
    return apply_filters('alttag_registrations_event_time', '');
}

/**
 * Get the event organization name
 *
 * @return string The event organization name
 */
function get_event_organization_name()
{
    return apply_filters('alttag_registrations_event_organization_name', '');
}

/**
 * Get the event organization contact email
 *
 * @return string The event organization contact email
 */
function get_event_organization_contact_email()
{
    return apply_filters('alttag_registrations_event_organization_contact_email', '');
}

/**
 * Get the event details
 *
 * @return array The event details
 */
function get_event_details()
{
    return [
        'name' => apply_filters('alttag_registrations_event_name', ''),
        'date' => get_event_dates(),
        'time' => get_event_time(),
        'location' => get_event_location(),
        'organization' => [
            'name' => get_event_organization_team_name(),
            'email' => get_event_organization_contact_email()
        ]
    ];
}

/**
 * Get the event name with date
 *
 * @return string The event name with date
 */
function get_event_name_with_date()
{
    return apply_filters('alttag_registrations_event_name_with_date', '');
}

/**
 * Get the event name
 *
 * @return string The event name
 */
function get_event_name()
{
    return apply_filters('alttag_registrations_event_name', '');
}

/**
 * Get the event name in locative case (declined form for "na X" / "v X"
 * sentences). Falls back to the nominative event name when no locative
 * value is configured on product meta or global settings.
 *
 * @return string The event name in locative case
 */
function get_event_name_locative()
{
    $value = apply_filters('alttag_registrations_event_name_locative', '');
    return $value !== '' ? $value : get_event_name();
}

/**
 * Get the event name in accusative case (declined form for "Pri vstupe na X"
 * type sentences where the preposition takes accusative). Falls back to the
 * nominative event name when no accusative value is configured.
 *
 * @return string The event name in accusative case
 */
function get_event_name_accusative()
{
    $value = apply_filters('alttag_registrations_event_name_accusative', '');
    return $value !== '' ? $value : get_event_name();
}

/**
 * Get the generic event noun ("podujatie", "event", "stretnutie", …). Reads
 * the per-product `_event_noun` meta first (via the event-meta context), then
 * the global `general.event_noun` setting, finally falls back to "podujatie".
 *
 * Exposed in placeholder maps as {event_noun} so templates and admin-editable
 * settings can refer to it without hardcoding a Slovak/English word.
 *
 * @return string
 */
function get_event_noun()
{
    return apply_filters('alttag_registrations_event_noun', '');
}

/**
 * Get the event noun in genitive case ("podujatia", "konferencie", …) for
 * sentences like "online stream of the {event_noun_genitive}". Falls back to
 * the nominative {event_noun} when no genitive form is configured.
 *
 * Exposed in placeholder maps as {event_noun_genitive}.
 *
 * @return string
 */
function get_event_noun_genitive()
{
    return apply_filters('alttag_registrations_event_noun_genitive', '');
}

/**
 * Get the event organization team name
 *
 * @return string The event organization team name
 */
function get_event_organization_team_name($order = null)
{
    return apply_filters('alttag_registrations_event_organization_team_name', '', $order);
}


/**
 * Get the event name with year
 *
 * @return string The event name with year
 */
function get_event_name_with_year()
{
    return apply_filters('alttag_registrations_event_name_with_year', '');
}

/**
 * Get the receipt from name
 *
 * @return string The receipt from name
 */
function get_receipt_title()
{
    return apply_filters('alttag_registrations_receipt_title', '');
}

/**
 * Get the mail from address
 *
 * @return string The mail from address
 */
function get_receipt_mail_from()
{
    return apply_filters('alttag_registrations_mail_from', '');
}

/**
 * Get the footer info text
 *
 * @return string The footer info text
 */
function get_receipt_footer_info_text()
{
    return apply_filters('alttag_registrations_footer_info_text', '');
}

/**
 * Get the info text
 *
 * @return string The info text
 */
function get_receipt_info_text()
{
    return apply_filters('alttag_registrations_info_text', '');
}

/**
 * Get the receipt subject
 *
 * @param WC_Order|null $order The order object
 * @return string The receipt subject
 */
function get_receipt_subject($order = null)
{
    return apply_filters('alttag_registrations_receipt_subject', sprintf(
        __('Your %s receipt', 'alttag-registrations'),
        get_event_name_with_year()
    ), $order);
}

/**
 * Get WooCommerce countries
 *
 * @return array Array of countries
 */
function get_woocommerce_countries()
{
    if (!function_exists('WC')) {
        return [];
    }

    $countries = WC()->countries->get_countries();
    return $countries;
}

/**
 * Get the full path to the QR PDFs directory
 *
 * @return string The full path to the QR PDFs directory
 */
function get_qr_pdfs_path()
{
    $upload_dir = wp_upload_dir();
    return $upload_dir['basedir'] . '/' . ALTTAG_REGISTRATIONS_QR_FOLDER;
}

/**
 * Get the URL to the QR PDFs directory
 *
 * @return string The URL to the QR PDFs directory
 */
function get_qr_pdfs_url()
{
    $upload_dir = wp_upload_dir();
    return $upload_dir['baseurl'] . '/' . ALTTAG_REGISTRATIONS_QR_FOLDER;
}

/**
 * Ensure the QR PDFs directory exists
 *
 * @return bool True if the directory exists or was created, false otherwise
 */
function ensure_qr_pdfs_directory_exists()
{
    $dir = get_qr_pdfs_path();

    if (!file_exists($dir)) {
        return wp_mkdir_p($dir);
    }

    return true;
}

/**
 * Check if the event is multi-day
 *
 * @return bool True if the event is multi-day, false otherwise
 */
function is_event_multi_day()
{
    return apply_filters('alttag_registrations_enable_multi_day', false);
}

/**
 * Get the available dates
 *
 * @return array The available dates
 */
function get_registrations_available_dates()
{
    return apply_filters('alttag_registrations_available_dates', []);
}

/**
 * Check if livestream is enabled
 *
 * @return bool True if livestream is enabled
 */
function is_livestream_enabled()
{
    return apply_filters('alttag_registrations_enable_livestream', false);
}

/**
 * Check if participant or order data represents a livestream user
 *
 * @param int|array $participant_or_order_data Participant ID or order data array
 * @return bool True if user is a livestream user
 */
function is_livestream_user($participant_or_order_data)
{
    // Return false if livestream is not enabled
    if (!is_livestream_enabled()) {
        return false;
    }

    // Handle participant ID
    if (is_numeric($participant_or_order_data)) {
        $participant_id = intval($participant_or_order_data);
        $is_livestream_user = filter_var(get_post_meta($participant_id, 'is_livestream_user', true), FILTER_VALIDATE_BOOLEAN);
        return $is_livestream_user;
    }

    // Handle order data array
    if (is_array($participant_or_order_data)) {
        $order_data = $participant_or_order_data;

        // Use filter to determine if this order data represents a livestream user
        return apply_filters('alttag_registrations_is_livestream_user', false, $order_data);
    }

    return false;
}

/**
 * Check if order has livestream products
 *
 * @param WC_Order|int $order Order object or order ID
 * @return bool True if order has livestream products
 */
function order_has_livestream_products($order)
{
    if (!is_livestream_enabled()) {
        return false;
    }

    // Handle order ID
    if (is_numeric($order)) {
        $order = wc_get_order($order);
    }

    if (!$order instanceof WC_Order) {
        return false;
    }

    // Use filter to check if order has livestream products
    return apply_filters('alttag_registrations_has_online_attendance_product', false, $order);
}

/**
 * Check if order contains in-person (non-livestream) products
 */
function order_has_inperson_products($order)
{
    if (is_numeric($order)) {
        $order = wc_get_order($order);
    }

    if (!$order instanceof \WC_Order) {
        return false;
    }

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && !product_is_livestream($product->get_id())) {
            return true;
        }
    }

    return false;
}

/**
 * Check if cart contains livestream products
 *
 * @return bool True if cart contains livestream products
 */
function cart_has_livestream_products()
{
    if (!is_livestream_enabled()) {
        return false;
    }

    // Use filter to check if cart contains livestream products
    return apply_filters('alttag_registrations_cart_contains_online_attendance_product', false);
}

/**
 * Check if order has only livestream products
 *
 * @param WC_Order|int $order Order object or order ID
 * @return bool True if order has only livestream products
 */
function order_has_only_livestream_product($order)
{
    // Handle order ID
    if (is_numeric($order)) {
        $order = wc_get_order($order);
    }

    if (!$order instanceof WC_Order) {
        return false;
    }

    // Use filter to check if order has only livestream products
    return apply_filters('alttag_registrations_order_has_only_livestream_product', false, $order);
}

/**
 * Get the livestream URL
 *
 * @return string The livestream URL
 */
function get_stream_url()
{
    return apply_filters('alttag_registrations_stream_url', '');
}

/**
 * Get the livestream link text
 *
 * @return string The livestream link text
 */
function get_stream_link_text()
{
    return apply_filters('alttag_registrations_stream_link_text', '');
}

/**
 * Get the livestream webhook URL
 *
 * @return string|null The livestream webhook URL or null if not configured
 */
function get_livestream_webhook_url()
{
    if (!is_livestream_enabled()) {
        return null;
    }

    return apply_filters('alttag_registrations_livestream_webhook_url', null);
}

/**
 * Sanitize HTML content for emails while preserving inline styles
 * Similar to wp_kses_post but allows style attributes
 *
 * @param string $content The HTML content to sanitize
 * @return string The sanitized HTML content
 */
function email_kses($content)
{
    $allowed_html = wp_kses_allowed_html('post');

    // Add style attribute to all allowed tags
    foreach ($allowed_html as $tag => $attributes) {
        $allowed_html[$tag]['style'] = true;
    }

    // Make sure these common email tags are allowed with styles
    $email_tags = ['div', 'span', 'p', 'a', 'img', 'table', 'tr', 'td', 'th', 'h1', 'h2', 'h3', 'ul', 'ol', 'li'];
    foreach ($email_tags as $tag) {
        if (!isset($allowed_html[$tag])) {
            $allowed_html[$tag] = [];
        }
        $allowed_html[$tag]['style'] = true;
        $allowed_html[$tag]['class'] = true;
    }

    return wp_kses($content, $allowed_html);
}

/**
 * Get the default language code
 *
 * @return string The default language code (e.g., 'sk', 'en')
 */
function get_default_language()
{
    return apply_filters('alttag_registrations_default_language', 'sk');
}

// ============================================================================
// Language Helper Functions
// ============================================================================

/**
 * Get current language from Polylang
 *
 * @return string Language code (e.g., 'sk', 'en') or default language
 */
function get_current_language()
{
    if (function_exists('pll_current_language')) {
        $lang = pll_current_language('slug');
        if ($lang) {
            return $lang;
        }
    }
    return get_default_language();
}

/**
 * Get WordPress locale from language code
 *
 * @param string $lang Language code (e.g., 'sk', 'en')
 * @return string WordPress locale (e.g., 'sk_SK', 'en_GB')
 */
function get_locale_from_lang($lang)
{
    $locales = apply_filters('alttag_registrations_language_locales', [
        'sk' => 'sk_SK',
        'en' => 'en_GB',
        'cs' => 'cs_CZ',
        'de' => 'de_DE',
    ]);

    $default_locale = $locales[get_default_language()] ?? 'sk_SK';
    return $locales[$lang] ?? $default_locale;
}

/**
 * Switch to a specific language
 *
 * @param string $lang Language code
 * @return void
 */
function switch_language($lang)
{
    if (function_exists('pll_current_language') && class_exists('PLL_Switcher')) {
        global $polylang;
        if ($polylang && isset($polylang->curlang)) {
            $polylang->curlang = $polylang->model->get_language($lang);
        }
    }

    switch_to_locale(get_locale_from_lang($lang));
}

/**
 * Restore the original language
 *
 * @return void
 */
function restore_language()
{
    restore_previous_locale();
}

/**
 * Load textdomain for specific locale
 *
 * @param string $locale Locale code (e.g., 'sk_SK', 'en_GB')
 * @return void
 */
function load_textdomains_for_locale($locale)
{
    $mu_plugins_dir = WPMU_PLUGIN_DIR;

    // Core plugin
    $core_mofile = $mu_plugins_dir . '/alttag-registrations/languages/alttag-registrations-' . $locale . '.mo';
    if (file_exists($core_mofile)) {
        load_textdomain('alttag-registrations', $core_mofile);
    }

    // Allow customization plugins to load their textdomains
    do_action('alttag_registrations_load_textdomains', $locale);
}

/**
 * Get language from order
 *
 * @param \WC_Order|int $order Order object or ID
 * @return string Language code
 */
function get_order_language($order)
{
    return ctx()->withOrder($order)->orderLanguage();
}

/**
 * Get language from participant
 *
 * @param int $participant_id
 * @return string Language code
 */
function get_participant_language($participant_id)
{
    return ctx()->withParticipant($participant_id)->participantLanguage();
}

/**
 * Execute callback in participant's language context
 *
 * @param int $participant_id Participant ID
 * @param callable $callback Function to execute
 * @return mixed Result of callback
 */
function with_participant_language($participant_id, $callback)
{
    $language = get_participant_language($participant_id);
    $original_locale = get_locale();
    $new_locale = get_locale_from_lang($language);

    // Switch to participant's language
    switch_language($language);

    // Reload text domains for the new locale
    unload_textdomain('alttag-registrations');
    load_textdomains_for_locale($new_locale);

    try {
        $result = $callback();
    } finally {
        // Restore original language
        restore_language();

        // Reload text domains for original locale
        unload_textdomain('alttag-registrations');
        load_textdomains_for_locale($original_locale);
    }

    return $result;
}

/**
 * Execute callback in order's language context
 *
 * @param int|\WC_Order $order Order ID or object
 * @param callable $callback Function to execute
 * @return mixed Result of callback
 */
function with_order_language($order, $callback)
{
    $language = get_order_language($order);
    $original_locale = get_locale();
    $new_locale = get_locale_from_lang($language);

    // Switch to order's language
    switch_language($language);

    // Reload text domains for the new locale
    unload_textdomain('alttag-registrations');
    load_textdomains_for_locale($new_locale);

    try {
        $result = $callback();
    } finally {
        // Restore original language
        restore_language();

        // Reload text domains for original locale
        unload_textdomain('alttag-registrations');
        load_textdomains_for_locale($original_locale);
    }

    return $result;
}

// ============================================================================
// Product Helper Functions
// ============================================================================

/**
 * Check if a product is a livestream/online product (by category)
 *
 * @param int|\WC_Product $product Product ID or object
 * @return bool
 */
function product_is_livestream($product)
{
    $product_id = is_numeric($product) ? (int) $product : ($product ? $product->get_id() : 0);
    if (!$product_id) {
        return false;
    }
    // Backwards-compatible: accept either the new slug 'livestream-product' or
    // the legacy 'online-product' until all sites finish migrating.
    return has_term(['livestream-product', 'online-product'], 'product_cat', $product_id);
}

/**
 * Check if a product is configured to skip session selection at checkout —
 * i.e. the buyer is registered for every session of the product and the
 * actual session is decided at scan time.
 *
 * @param int|\WC_Product $product Product ID or object
 * @return bool
 */
function product_skips_session_selection($product)
{
    $product_id = is_numeric($product) ? (int) $product : ($product ? $product->get_id() : 0);
    if (!$product_id) {
        return false;
    }
    return get_post_meta($product_id, '_alttag_skip_session_selection', true) === '1';
}

/**
 * Check if a product generates a PDF ticket
 *
 * @param int|\WC_Product $product Product ID or object
 * @return bool
 */
function product_generates_ticket($product)
{
    $product_id = is_numeric($product) ? (int) $product : ($product ? $product->get_id() : 0);
    if (!$product_id) {
        return true;
    }
    if (product_is_livestream($product_id)) {
        return false;
    }
    // Add-on products (social evening, gala dinner, etc.) are not primary
    // ticket products — exclude them from any UI that lists ticketable
    // products (Ticket Designer per-product selector, etc.) and from any
    // automatic ticket generation.
    if (get_post_meta($product_id, '_alttag_addon_product', true) === '1') {
        return false;
    }
    $value = get_post_meta($product_id, '_generates_ticket', true);
    return $value === '' ? true : (bool) $value;
}

/**
 * Check if a product should show QR code in email
 *
 * @param int|\WC_Product $product Product ID or object
 * @return bool
 */
function product_show_qr_code($product)
{
    return !product_is_livestream($product);
}

/**
 * Check if a product is for in-person attendance
 *
 * @param int|\WC_Product $product Product ID or object
 * @return bool
 */
function is_inperson_attendance_product($product)
{
    $product_id = is_numeric($product) ? (int) $product : ($product ? $product->get_id() : 0);
    if (!$product_id) {
        return false;
    }
    return !product_is_livestream($product_id);
}

/**
 * Check if a product is for online attendance
 *
 * @param int|\WC_Product $product Product ID or object
 * @return bool
 */
function is_online_attendance_product($product)
{
    $product_id = is_numeric($product) ? (int) $product : ($product ? $product->get_id() : 0);
    if (!$product_id) {
        return false;
    }
    return product_is_livestream($product_id);
}

// ============================================================================
// Cart Helper Functions
// ============================================================================

/**
 * Check if cart contains a product matching the given check function
 *
 * @param callable $check_function
 * @return bool
 */
function cart_contains_product_matching($check_function)
{
    if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
        return false;
    }

    foreach (WC()->cart->get_cart() as $cart_item) {
        if (!isset($cart_item['product_id'])) {
            continue;
        }
        if ($check_function($cart_item['product_id'])) {
            return true;
        }
    }

    return false;
}

/**
 * Check if cart contains an in-person attendance product
 *
 * @return bool
 */
function cart_contains_inperson_attendance_product()
{
    return cart_contains_product_matching(__NAMESPACE__ . '\is_inperson_attendance_product');
}

/**
 * Check if cart contains an online attendance product
 *
 * @return bool
 */
function cart_contains_online_attendance_product()
{
    return cart_contains_product_matching(__NAMESPACE__ . '\is_online_attendance_product');
}

/**
 * Check if order has a livestream product
 *
 * @param \WC_Order $order
 * @return bool
 */
function has_online_attendance_product($order)
{
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && product_is_livestream($product->get_id())) {
            return true;
        }
    }
    return false;
}

/**
 * Check if a product belongs to the configured membership category.
 *
 * Category slug comes from Settings (`checkout.membership_product_category`).
 *
 * @param int|\WC_Product $product
 * @return bool
 */
function product_is_membership($product)
{
    $category = Settings::getValue('membership.product_category');
    if (empty($category)) {
        return false;
    }

    $product_id = is_numeric($product) ? (int) $product : ($product ? $product->get_id() : 0);
    if (!$product_id) {
        return false;
    }

    return has_term($category, 'product_cat', $product_id);
}

/**
 * Check if cart contains at least one membership product.
 *
 * @return bool
 */
function cart_contains_membership_product()
{
    return cart_contains_product_matching(__NAMESPACE__ . '\product_is_membership');
}

/**
 * Check if order has at least one membership product.
 *
 * @param \WC_Order $order
 * @return bool
 */
function has_membership_product($order)
{
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && product_is_membership($product)) {
            return true;
        }
    }
    return false;
}

/**
 * Get the primary product name from an order (first line item).
 *
 * @param \WC_Order $order
 * @return string
 */
function get_order_product_name($order)
{
    if (!$order instanceof \WC_Order) {
        return '';
    }

    foreach ($order->get_items() as $item) {
        if ($item->get_type() === 'line_item') {
            return $item->get_name();
        }
    }

    return '';
}

/**
 * Check if a participant is a livestream/online user
 *
 * @param int $participant_id
 * @return bool
 */
function is_participant_livestream_user($participant_id)
{
    return ctx()->withParticipant($participant_id)->isLivestreamParticipant();
}

/**
 * Check if a participant should receive a ticket
 *
 * @param int $participant_id
 * @return bool
 */
function should_participant_get_ticket($participant_id)
{
    return ctx()->withParticipant($participant_id)->shouldShowTicket();
}

/**
 * Check if an order should display ticket download
 *
 * @param \WC_Order $order
 * @return bool
 */
function should_order_show_ticket($order)
{
    return ctx()->withOrder($order)->shouldShowTicket();
}

/**
 * Get product IDs currently in cart
 *
 * @return array
 */
function get_cart_product_ids()
{
    if (!function_exists('WC') || !WC()->cart) {
        return [];
    }

    $ids = [];
    foreach (WC()->cart->get_cart() as $cart_item) {
        $ids[] = (int) $cart_item['product_id'];
    }
    return $ids;
}

/**
 * Convert an admin-entered participant/selection price to the WC internal
 * price. Merchant-entered prices are NET (excluding VAT) — VAT is added on
 * top at checkout — so we pass the price straight through regardless of the
 * WC "prices_include_tax" option. WooCommerce handles tax calculation from
 * that stored NET value.
 *
 * @param float       $price   Admin-entered NET price
 * @param \WC_Product $product (unused, kept for API compatibility)
 * @return float
 */
function gross_to_wc_price($price, $product)
{
    return $price;
}

// ============================================================================
// Selection / Multi-Day Utility Functions
// ============================================================================

/**
 * Get per-day participant data for a participant.
 * Tries participant meta first, then falls back to order item meta.
 *
 * @param int $participant_id
 * @return array [date => count, ...]
 */
function get_participant_days_data($participant_id)
{
    return ctx()->withParticipant($participant_id)->selectedDaysData();
}

/**
 * Get available date labels for a participant from their product
 *
 * @param int $participant_id
 * @return array [date => label, ...]
 */
function get_participant_available_date_labels($participant_id)
{
    return ctx()->withParticipant($participant_id)->availableDateLabels();
}

/**
 * Format selected days as a readable label
 *
 * @param array $selected_days Array of date strings
 * @return string
 */
function get_selected_days_label($selected_days)
{
    if (empty($selected_days)) {
        return '';
    }

    $available_dates = get_registrations_available_dates();
    $labels = array_map(function ($date) use ($available_dates) {
        return $available_dates[$date] ?? $date;
    }, $selected_days);

    return implode(', ', $labels);
}

/**
 * Format selected days with per-day participant counts
 *
 * @param array $days_data [date => count, ...]
 * @return string
 */
function get_selected_days_data_label($days_data)
{
    if (empty($days_data) || !is_array($days_data)) {
        return '';
    }

    $available_dates = get_registrations_available_dates();
    $has_multi = false;
    foreach ($days_data as $count) {
        if ($count > 1) {
            $has_multi = true;
            break;
        }
    }

    $parts = [];
    foreach ($days_data as $date => $count) {
        $label = $available_dates[$date] ?? $date;
        if ($has_multi) {
            $count = (int) $count;
            $persons = sprintf(_n('%d person', '%d persons', $count, 'alttag-registrations'), $count);
            $parts[] = $label . ' (' . $persons . ')';
        } else {
            $parts[] = $label;
        }
    }

    return implode(', ', $parts);
}

/**
 * Get total persons across all days from days_data
 *
 * @param array $days_data [date => count, ...]
 * @return int
 */
function get_total_persons_from_days_data($days_data)
{
    if (empty($days_data) || !is_array($days_data)) {
        return 0;
    }
    return array_sum($days_data);
}

/**
 * Format days data with explicit labels
 *
 * @param array $days_data [date => count, ...]
 * @param array $labels [date => label, ...]
 * @return string
 */
function format_days_data_with_labels($days_data, $labels)
{
    if (empty($days_data)) {
        return '';
    }

    $has_multi = false;
    foreach ($days_data as $count) {
        if ($count > 1) {
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
        } else {
            $parts[] = $label;
        }
    }
    return implode(', ', $parts);
}

/**
 * Format days array with explicit labels
 *
 * @param array $days
 * @param array $labels [date => label, ...]
 * @return string
 */
function format_days_with_labels($days, $labels)
{
    if (empty($days)) {
        return '';
    }
    $parts = array_map(function ($date) use ($labels) {
        return $labels[$date] ?? $date;
    }, $days);
    return implode(', ', $parts);
}

// ============================================================================
// Participant State Helper
// ============================================================================

/**
 * Convert relative path (from WP root) to absolute path
 *
 * @param string $relative_path
 * @return string
 */
function get_absolute_path($relative_path)
{
    $wp_root = str_replace('\\', '/', ABSPATH);
    $relative_path = str_replace('\\', '/', $relative_path);

    if (substr($relative_path, 0, 1) === '/') {
        $relative_path = substr($relative_path, 1);
    }

    return $wp_root . $relative_path;
}

/**
 * Get ProductConfig for a product ID
 *
 * @param int $product_id
 * @return ProductConfig|null
 */
function get_product_config($product_id)
{
    return ProductConfig::get($product_id);
}

/**
 * Get the current RegistrationContext
 *
 * @return RegistrationContext
 */
function ctx()
{
    return RegistrationContext::current();
}

/**
 * Get ParticipantState for a participant ID
 *
 * @param int $participant_id
 * @return ParticipantState|null
 */
function get_participant_state($participant_id)
{
    return ParticipantState::get($participant_id);
}

/**
 * Get ParticipantState from an order ID
 *
 * @param int $order_id
 * @return ParticipantState|null
 */
function get_participant_state_by_order($order_id)
{
    return ctx()->withOrder($order_id)->participant();
}

/**
 * Write a focused ticket designer debug line to uploads log.
 *
 * @param string $event
 * @param array $data
 * @return void
 */
function ticket_designer_debug_log($event, array $data = [])
{
    $upload_dir = wp_upload_dir();
    $log_file = trailingslashit($upload_dir['basedir']) . 'alttag-ticket-designer-debug.log';

    $payload = [
        'ts' => current_time('mysql'),
        'event' => $event,
        'data' => $data,
    ];

    error_log(wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, 3, $log_file);
}

/**
 * Summarize ticket design settings for debug logging.
 *
 * @param array $settings
 * @return array
 */
function ticket_designer_debug_settings_summary($settings)
{
    if (!is_array($settings)) {
        return ['invalid' => true];
    }

    $content_rows = isset($settings['content_rows']) && is_array($settings['content_rows'])
        ? $settings['content_rows']
        : [];

    $rows = [];
    foreach ($content_rows as $index => $row) {
        $type = isset($row['type']) ? (string) $row['type'] : '';
        $entry = [
            'index' => $index,
            'type' => $type,
        ];

        if ($type === 'image') {
            $entry['image'] = ticket_designer_debug_basename($row['image_url'] ?? '');
        } elseif ($type === 'logo_row') {
            $logos = [];
            foreach (($row['logos'] ?? []) as $logo) {
                $logos[] = ticket_designer_debug_basename($logo['image_url'] ?? '');
            }
            $entry['logos'] = $logos;
        } elseif ($type === 'qr_code') {
            $entry['qr'] = (string) ($row['qr_content'] ?? '');
        } elseif ($type === 'text') {
            $text = wp_strip_all_tags(html_entity_decode((string) ($row['text'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $text = trim(preg_replace('/\s+/', ' ', $text));
            $entry['text'] = function_exists('mb_substr') ? mb_substr($text, 0, 120) : substr($text, 0, 120);
        }

        $rows[] = $entry;
    }

    return [
        'header_image' => ticket_designer_debug_basename($settings['header_image'] ?? ''),
        'row_count' => count($content_rows),
        'rows' => $rows,
    ];
}

/**
 * Get filename from URL/path for compact debug logs.
 *
 * @param string $value
 * @return string
 */
/**
 * Get the date format used across the plugin. Filterable.
 *
 * @param string $context Context hint: 'display', 'compact', 'ticket'
 * @return string Date format string for date_i18n()
 */
function get_date_format($context = 'display')
{
    $default = $context === 'compact' ? get_option('date_format', 'j. n. Y') : 'j. n. Y';
    return apply_filters('alttag_registrations_date_format', $default, $context);
}

/**
 * Format a date string using the plugin's filterable date format.
 *
 * @param string $date Date in Y-m-d or other parseable format
 * @param string $context Context hint
 * @return string Formatted date
 */
function format_date($date, $context = 'display')
{
    $ts = strtotime($date);
    return $ts ? date_i18n(get_date_format($context), $ts) : $date;
}

function ticket_designer_debug_basename($value)
{
    $path = parse_url((string) $value, PHP_URL_PATH);
    if (is_string($path) && $path !== '') {
        return basename($path);
    }

    return basename((string) $value);
}
