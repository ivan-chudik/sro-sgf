<?php

namespace Alttag\Registrations\Module;

use Alttag\Registrations\Settings;

use function Alttag\Registrations\ctx;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce-product based hotel-room selection at checkout. Knows nothing
 * about specific events, hotels, product IDs or pricing rules — those are all
 * read from configuration / product meta / filters.
 *
 * What the module does
 * --------------------
 *  - Discovers hotel products from the configured product_cat slug.
 *  - Activates per-cart via the `_alttag_module_hotel_selection` opt-in meta
 *    on event/ticket products in the cart.
 *  - Renders an accommodation block on checkout: a checkbox, a room-type
 *    selector, a persons selector, and a guest field set per additional
 *    person beyond the first.
 *  - Adds the chosen hotel product as its own cart line.
 *  - Persists the selection to order + participant meta.
 *
 * Persons range
 * -------------
 *  Min/max persons are properties of each hotel product — they are NOT a
 *  side-effect of "differential pricing exists". A product can be a flat-rate
 *  room that's still bookable for 2..4 persons. Defaults: min = 1, max =
 *  global setting; both are overridable through the filters
 *  `alttag_hotel_min_persons` and `alttag_hotel_max_persons`.
 *
 * Pricing
 * -------
 *  The cart line price is resolved by `getProductPriceForPersons()`, which
 *  delegates to a default strategy chain (per-person price matrix → explicit
 *  1- and 2-person prices → per-extra-person surcharge → flat product price)
 *  and then exposes the result through the
 *  `alttag_hotel_product_price_for_persons` filter. The selection module does
 *  not encode any specific pricing model itself; per-product strategies are
 *  driven entirely by post meta.
 *
 * Customization hooks
 * -------------------
 *  - `alttag_hotel_min_persons` / `alttag_hotel_max_persons` — per-product
 *    persons range (filters, $value, $product).
 *  - `alttag_hotel_product_price_for_persons` — final cart line price
 *    (filter, $price, $product, $persons).
 *  - `alttag_hotel_email_after_info` / `alttag_hotel_thankyou_after_info` —
 *    fired after the default "extra ticket" reminder block in the order
 *    email / thank-you page so site customizations can append additional
 *    notices on top of the default copy.
 *
 * Selection state is persisted to WC session so it survives checkout AJAX
 * refreshes; on order creation it is saved as order meta and copied to the
 * participant record.
 */
class HotelSelectionModule extends AbstractModule
{
    private const SESSION_KEY = 'alttag_hotel_selection';
    private const SESSIONS_KEY = 'alttag_hotel_selections';
    private const ORDER_META_KEY = 'hotel_selection';
    private const ORDER_META_NIGHTS_KEY = 'hotel_selections';

    public function getId(): string
    {
        return 'hotel_selection';
    }

    public function getName(): string
    {
        return __('Hotel selection', 'alttag-registrations');
    }

    public function getDescription(): string
    {
        return __(
            'Adds a hotel-product picker to checkout. Products in the configured taxonomy term'
            . ' become room-type options. Each product can opt into per-person pricing'
            . ' (a price matrix by person count, explicit 1- and 2-person prices, or a'
            . ' per-extra-person surcharge) and define its own min/max persons range.'
            . ' Customizations can append site-specific reminders via the'
            . ' alttag_hotel_email_after_info / alttag_hotel_thankyou_after_info actions.',
            'alttag-registrations'
        );
    }

    public function getSettingsTab(): ?string
    {
        return 'modules';
    }

    public function hasProductToggle(): bool
    {
        return true;
    }

    /**
     * Strict opt-in: the hotel selector only shows when the cart product has
     * `_alttag_module_hotel_selection = '1'`. The global enable flag still
     * controls whether the module boots its hooks at all, but per-product
     * activation defaults to OFF (unlike AbstractModule's "fall back to
     * global" behavior).
     */
    public function isEnabledForProduct(int $product_id): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $meta = get_post_meta($product_id, '_alttag_module_' . $this->getId(), true);
        return $meta === '1';
    }

    /**
     * Whether any product currently in the cart has the hotel selector
     * enabled.
     */
    private function isActiveForCart(): bool
    {
        $cart = ctx()->cart();
        if ($cart->isEmpty) {
            return false;
        }

        $hotel_ids = array_keys($this->getAvailableProducts());
        foreach (array_map('intval', $cart->productIds) as $product_id) {
            // Skip the hotel product itself — its toggle would be irrelevant.
            if (in_array($product_id, $hotel_ids, true)) {
                continue;
            }
            if ($this->isEnabledForProduct($product_id)) {
                return true;
            }
        }
        return false;
    }

    public function getSettingsFields(): array
    {
        return [
            'hotel_selection' => [
                'category_slug' => [
                    'label' => __('Hotel product category slug', 'alttag-registrations'),
                    'type' => 'text',
                    'default' => 'hotel',
                    'description' => __(
                        'Products in this product_cat taxonomy term are offered as room types.',
                        'alttag-registrations'
                    ),
                ],
                'accommodation_label' => [
                    'label' => __('Accommodation checkbox label', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'default' => __('I am interested in accommodation', 'alttag-registrations'),
                ],
                'room_type_label' => [
                    'label' => __('Room type field label', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'default' => __('Type of room', 'alttag-registrations'),
                ],
                'persons_label' => [
                    'label' => __('Persons field label', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'default' => __('Persons', 'alttag-registrations'),
                ],
                'guest_first_name_label' => [
                    'label' => __('Second guest first name label', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'default' => __('Second guest first name', 'alttag-registrations'),
                ],
                'guest_last_name_label' => [
                    'label' => __('Second guest last name label', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'default' => __('Second guest last name', 'alttag-registrations'),
                ],
                'guest_name_label' => [
                    'label' => __('Second guest name label (display)', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'default' => __('Second guest name', 'alttag-registrations'),
                    'description' => __(
                        'Used for display only (cart summary, email, invoice, thank-you).'
                        . ' Two separate first/last name inputs are shown at checkout.',
                        'alttag-registrations'
                    ),
                ],
                'guest_email_label' => [
                    'label' => __('Second guest email label', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'default' => __('Second guest email', 'alttag-registrations'),
                ],
                'send_guest_notification' => [
                    'label' => __('Send notification email to second guest', 'alttag-registrations'),
                    'type' => 'checkbox',
                    'default' => true,
                    'description' => __(
                        'When the order is paid, send the second guest an email about'
                        . ' the accommodation and the need to purchase a conference ticket separately.',
                        'alttag-registrations'
                    ),
                ],
                'guest_notification_subject' => [
                    'label' => __('Guest notification – email subject', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'description' => __('Supports {event_name}.', 'alttag-registrations'),
                ],
                'guest_notification_heading' => [
                    'label' => __('Guest notification – email heading', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'description' => __('Supports {event_name}.', 'alttag-registrations'),
                ],
                'guest_notification_intro' => [
                    'label' => __('Guest notification – intro paragraph', 'alttag-registrations'),
                    'type' => 'textarea',
                    'translatable' => true,
                    'description' => __(
                        'Supports {event_name}, {event_dates}, {hotel_guest_name}.',
                        'alttag-registrations'
                    ),
                ],
                'guest_notification_registrant_text' => [
                    'label' => __('Guest notification – registrant info', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'description' => __(
                        'Supports {registrant_name}, {registrant_email}. Hidden when both are empty.',
                        'alttag-registrations'
                    ),
                ],
                'guest_notification_notice_title' => [
                    'label' => __('Guest notification – notice title', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                ],
                'guest_notification_notice_body' => [
                    'label' => __('Guest notification – notice body', 'alttag-registrations'),
                    'type' => 'textarea',
                    'translatable' => true,
                    'description' => __(
                        'Supports {registration_url} (rendered as a link).',
                        'alttag-registrations'
                    ),
                ],
                'guest_notification_outro' => [
                    'label' => __('Guest notification – outro paragraph', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                ],
                'guest_notification_registration_url' => [
                    'label' => __('Guest notification – registration URL', 'alttag-registrations'),
                    'type' => 'text',
                    'description' => __('URL where the second guest can buy a ticket.', 'alttag-registrations'),
                ],
                'max_persons' => [
                    'label' => __('Maximum persons per room', 'alttag-registrations'),
                    'type' => 'number',
                    'default' => '2',
                    'description' => __(
                        'Default maximum value in the persons selector. Individual hotel '
                        . 'products can override it.',
                        'alttag-registrations'
                    ),
                ],
                // Per-product price overrides — hidden from the global Settings UI;
                // they only make sense at the product level. EventManager auto-renders
                // them in the product Event Details tab via product_override.
                'price_1_person' => [
                    'label' => __('Hotel: price for 1 person', 'alttag-registrations'),
                    'type' => 'text',
                    'product_override' => true,
                    'product_meta_key' => '_hotel_price_1_person',
                    'settings_hidden' => true,
                    'description' => __(
                        'Price applied when this hotel product is purchased with 1 person.',
                        'alttag-registrations'
                    ),
                ],
                'price_2_persons' => [
                    'label' => __('Hotel: price for 2 persons', 'alttag-registrations'),
                    'type' => 'text',
                    'product_override' => true,
                    'product_meta_key' => '_hotel_price_2_persons',
                    'settings_hidden' => true,
                    'description' => __(
                        'Price applied when this hotel product is purchased with 2 persons.'
                        . ' Setting both 1- and 2-person prices enables the default'
                        . ' explicit per-person pricing strategy.',
                        'alttag-registrations'
                    ),
                ],
                'extra_person_price' => [
                    'label' => __('Hotel: surcharge per extra person', 'alttag-registrations'),
                    'type' => 'text',
                    'product_override' => true,
                    'product_meta_key' => '_hotel_extra_person_price',
                    'settings_hidden' => true,
                    'description' => __(
                        'Amount added per additional person beyond the first. '
                        . 'Used when no price matrix or explicit 1-/2-person price '
                        . 'matches the selected persons count. Persons selector '
                        . 'visibility is controlled by the product min/max persons range.',
                        'alttag-registrations'
                    ),
                ],
                'price_by_person_count' => [
                    'label' => __('Hotel: price by person count', 'alttag-registrations'),
                    'type' => 'text',
                    'product_override' => true,
                    'product_meta_key' => '_hotel_price_by_person_count',
                    'settings_hidden' => true,
                    'description' => __(
                        'Optional non-linear price matrix. Use semicolon- or '
                        . 'newline-separated entries in the form "count:price" '
                        . 'or "count=price", e.g. "1:100; 2:180; 3:250; 4:320". '
                        . 'When the chosen persons count matches an entry, that '
                        . 'price is used; missing counts fall through to the '
                        . 'other pricing options below.',
                        'alttag-registrations'
                    ),
                ],
                'min_persons' => [
                    'label' => __('Hotel: minimum persons', 'alttag-registrations'),
                    'type' => 'text',
                    'product_override' => true,
                    'product_meta_key' => '_hotel_min_persons',
                    'settings_hidden' => true,
                    'description' => __(
                        'Lowest value the persons selector offers for this product. Defaults to 1.',
                        'alttag-registrations'
                    ),
                ],
                'max_persons_product' => [
                    'label' => __('Hotel: maximum persons', 'alttag-registrations'),
                    'type' => 'text',
                    'product_override' => true,
                    'product_meta_key' => '_hotel_max_persons',
                    'settings_hidden' => true,
                    'description' => __(
                        'Highest value the persons selector offers for this product. '
                        . 'Defaults to the global "Maximum persons per room" setting.',
                        'alttag-registrations'
                    ),
                ],
            ],
        ];
    }

    public function registerHooks(): void
    {
        add_filter('woocommerce_checkout_fields', [$this, 'addCheckoutFields'], 16);
        add_filter('woocommerce_form_field_hotel_repeater', [$this, 'renderRepeaterField'], 10, 4);
        add_action('woocommerce_after_checkout_validation', [$this, 'validateFields'], 10, 2);

        add_action('wp_ajax_alttag_update_hotel_cart', [$this, 'handleCartUpdate']);
        add_action('wp_ajax_nopriv_alttag_update_hotel_cart', [$this, 'handleCartUpdate']);

        add_action('woocommerce_before_calculate_totals', [$this, 'applyCustomCartPrice'], 20);
        add_filter('woocommerce_get_item_data', [$this, 'renderCartItemMeta'], 10, 2);

        add_action('woocommerce_checkout_create_order_line_item', [$this, 'saveLineItemMeta'], 10, 4);
        add_filter('woocommerce_hidden_order_itemmeta', [$this, 'hideRawItemMeta']);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'saveSelectionToOrder'], 20);
        add_action('alttag_registrations_participant_create', [$this, 'saveSelectionToParticipant'], 10, 2);

        add_action('alttag_registrations_email_after_custom_fields', [$this, 'renderEmailInfo'], 10, 1);
        add_action('alttag_registrations_checkout_after_download_links', [$this, 'renderThankYouInfo'], 10, 1);
        // The invoice "Poznámka" hook used to duplicate guest names + emails
        // that now live in the line-item meta. Kept the method around for
        // programmatic callers but no longer wired in by default.
        // add_filter('alttag_registrations_invoice_data', [$this, 'appendInvoiceGuestInfo'], 6, 3);

        // Notify the second guest after the order is paid (priority 30 — after the
        // CompanionModule auto-register hook at 25, which we don't want to fire
        // for hotel-only second guests).
        add_action('woocommerce_order_status_completed', [$this, 'sendGuestNotification'], 30);

        add_filter('alttag_registrations_participant_columns', [$this, 'addAdminColumns']);
        add_filter('alttag_registrations_participant_column_content', [$this, 'renderAdminColumn'], 10, 3);
        add_filter('alttag_registrations_export_fields', [$this, 'addExportFields'], 10, 2);
        add_filter('alttag_registrations_meta_fields', [$this, 'registerMetaFields']);
        add_filter('alttag_registrations_meta_box_sections', [$this, 'addMetaBoxSection']);

        // Admin list: hotel filter dropdown + meta query + multi-night export
        // expansion. All three plug into existing generic hooks so nothing
        // in core knows about hotels specifically.
        add_action('alttag_registrations_participant_after_filters', [$this, 'renderHotelAdminFilter']);
        add_filter('alttag_registrations_participant_filters_query', [$this, 'applyHotelAdminFilterQuery']);
        add_filter('alttag_registrations_export_participant_rows', [$this, 'expandExportRowsPerNight'], 10, 2);

        // Public verification page (/verify/{vs}/) — render hotel section
        add_filter('alttag_registrations_verification_sections', [$this, 'addVerificationFields']);
        add_filter('alttag_registrations_customer_data', [$this, 'populateVerificationData'], 10, 3);
        add_filter('alttag_registrations_verification_field_value', [$this, 'formatVerificationValue'], 10, 3);
        add_filter('alttag_registrations_verification_field_label', [$this, 'getVerificationLabel'], 10, 3);

        add_action('wp_footer', [$this, 'renderCheckoutJs']);
    }

    // =========================================================================
    // Hotel product discovery
    // =========================================================================

    /**
     * Available hotel products as ['id' => $product, ...] keyed by id, sorted
     * by menu_order/title. Out-of-stock products are excluded.
     */
    public function getAvailableProducts(): array
    {
        $category = Settings::getValue('hotel_selection.category_slug');
        if ($category === '') {
            return [];
        }

        $products = wc_get_products([
            'category' => [$category],
            'status' => 'publish',
            'limit' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $current_lang = $this->getCurrentLanguage();

        $result = [];
        foreach ($products as $product) {
            if (!$product instanceof \WC_Product) {
                continue;
            }
            // Polylang: only show products whose translation matches the
            // current frontend language. When the helper is unavailable or
            // returns nothing, accept the product (single-language fallback).
            if (function_exists('pll_get_post_language') && $current_lang !== '') {
                $product_lang = pll_get_post_language($product->get_id(), 'slug');
                if ($product_lang && $product_lang !== $current_lang) {
                    continue;
                }
            }
            // Hide products that are explicitly out-of-stock
            if ($product->managing_stock() && (int) $product->get_stock_quantity() <= 0) {
                continue;
            }
            $result[$product->get_id()] = $product;
        }

        return $result;
    }

    /**
     * Group available products into "nights" by their `hotel-day-N` sub-category.
     *
     * Returns ['<night_slug>' => ['label' => ..., 'products' => [id => WC_Product, …]], …].
     * Sorted by the numeric suffix of the slug (hotel-day-0, hotel-day-1, …).
     * When no day-N sub-categories are assigned, falls back to a single
     * "default" night containing all hotel products so the module keeps
     * working on sites that have not split their inventory.
     *
     * @return array<string, array{label: string, products: array<int, \WC_Product>}>
     */
    public function getAvailableNights(): array
    {
        $products = $this->getAvailableProducts();
        if (empty($products)) {
            return [];
        }

        $nights = [];
        $unassigned = [];

        foreach ($products as $id => $product) {
            $terms = wp_get_object_terms($id, 'product_cat');
            $matched = null;
            foreach ($terms as $term) {
                if (preg_match('/^hotel-day-(\d+)$/', $term->slug)) {
                    $matched = $term;
                    break;
                }
            }
            if ($matched) {
                if (!isset($nights[$matched->slug])) {
                    // Filter the displayed night label — lets customisation
                    // plugins replace generic taxonomy names with concrete
                    // date ranges (e.g. "7.–8. 6. 2026 (noc pred akciou)")
                    // without renaming the underlying product_cat terms.
                    $label = apply_filters(
                        'alttag_hotel_night_label',
                        $matched->name,
                        $matched->slug,
                        $matched
                    );
                    $nights[$matched->slug] = [
                        'label' => (string) $label,
                        'products' => [],
                        '_order' => (int) preg_replace('/^hotel-day-/', '', $matched->slug),
                    ];
                }
                $nights[$matched->slug]['products'][$id] = $product;
            } else {
                $unassigned[$id] = $product;
            }
        }

        // Sort nights by numeric suffix so 0 < 1 < 2 …
        uasort($nights, static function ($a, $b) {
            return $a['_order'] <=> $b['_order'];
        });
        foreach ($nights as &$n) {
            unset($n['_order']);
        }
        unset($n);

        // No day-N sub-categories at all — wrap everything into one implicit
        // night so the rest of the module can treat single-night sites
        // identically to multi-night ones.
        if (empty($nights)) {
            $nights['default'] = [
                'label' => Settings::getValue('hotel_selection.accommodation_label'),
                'products' => $unassigned,
            ];
        } elseif (!empty($unassigned)) {
            // Mixed: append unassigned products as a final implicit night.
            $nights['default'] = [
                'label' => __('Other rooms', 'alttag-registrations'),
                'products' => $unassigned,
            ];
        }

        return $nights;
    }

    /**
     * Resolve the current request language as a Polylang slug. Returns an
     * empty string when Polylang is not active or the slug is unavailable;
     * `getAvailableProducts()` then skips language filtering entirely so
     * single-language sites keep working unchanged.
     */
    private function getCurrentLanguage(): string
    {
        if (function_exists('pll_current_language')) {
            $lang = pll_current_language('slug');
            return is_string($lang) && $lang !== '' ? $lang : '';
        }
        return '';
    }

    /**
     * Build the persons-selector options 1..globalMax. JS narrows the visible
     * options to each product's own min/max range when a product is selected.
     */
    private function buildPersonCountOptions(): array
    {
        $max = $this->getEffectiveMaxPersons();
        $options = ['' => __('— select —', 'alttag-registrations')];
        for ($i = 1; $i <= $max; $i++) {
            $options[(string) $i] = sprintf(
                _n('%d person', '%d persons', $i, 'alttag-registrations'),
                $i
            );
        }
        return $options;
    }

    private function getGlobalMaxPersons(): int
    {
        return max(1, (int) Settings::getValue('hotel_selection.max_persons'));
    }

    /**
     * Highest persons count we may need across all hotel products — used as
     * the upper bound for rendered guest field sets and the persons-selector
     * option pool. Considers per-product max_persons overrides so a single
     * room with max=4 still gets its 3rd and 4th guest fields rendered even
     * when the global setting stays at 2.
     */
    private function getEffectiveMaxPersons(): int
    {
        $max = $this->getGlobalMaxPersons();
        foreach ($this->getAvailableProducts() as $product) {
            $product_max = $this->getProductMaxPersons($product);
            if ($product_max > $max) {
                $max = $product_max;
            }
        }
        return $max;
    }

    private function getProductMinPersons(\WC_Product $product): int
    {
        $min = get_post_meta($product->get_id(), '_hotel_min_persons', true);
        $value = $min === '' ? 1 : max(1, (int) $min);
        return (int) apply_filters('alttag_hotel_min_persons', $value, $product);
    }

    private function getProductMaxPersons(\WC_Product $product): int
    {
        $max = get_post_meta($product->get_id(), '_hotel_max_persons', true);
        $value = $max === '' ? $this->getGlobalMaxPersons() : max(1, (int) $max);
        return (int) apply_filters('alttag_hotel_max_persons', $value, $product);
    }

    /**
     * Resolve the cart line price for the chosen hotel product + persons count.
     *
     * The selection module itself knows nothing specific about pricing rules —
     * it consults the default strategy for the bundled meta-driven options
     * (explicit 1- and 2-person prices, per-extra-person surcharge) and then
     * exposes the result through the `alttag_hotel_product_price_for_persons`
     * filter so customizations or alternative resolvers can substitute their
     * own pricing strategy without touching this class.
     */
    private function getProductPriceForPersons(\WC_Product $product, int $persons): float
    {
        $price = $this->resolveDefaultProductPriceForPersons($product, $persons);

        return (float) apply_filters(
            'alttag_hotel_product_price_for_persons',
            $price,
            $product,
            $persons
        );
    }

    /**
     * Default pricing strategies. Tries, in order:
     *
     *   1. Per-person price matrix — `_hotel_price_by_person_count` post meta;
     *      matches the chosen persons count to an explicit price for arbitrary
     *      non-linear pricing curves. When the count is not in the matrix the
     *      strategy falls through to the next option below.
     *   2. Explicit 1-person + 2-person prices — `_hotel_price_1_person` /
     *      `_hotel_price_2_persons` post meta (caps at 2).
     *   3. Per-extra-person surcharge — `_hotel_extra_person_price` post meta;
     *      price = base + (persons − 1) × surcharge.
     *   4. Plain product price as a flat rate regardless of persons count.
     *
     * Each strategy reads only product meta (no IDs, no event-specific
     * constants), so this method stays generic. Override the public price via
     * the `alttag_hotel_product_price_for_persons` filter to plug in any other
     * pricing model.
     */
    private function resolveDefaultProductPriceForPersons(\WC_Product $product, int $persons): float
    {
        $product_id = $product->get_id();

        $matrix = $this->parsePriceByPersonCount(
            (string) get_post_meta($product_id, '_hotel_price_by_person_count', true)
        );
        if (!empty($matrix) && isset($matrix[$persons])) {
            return (float) $matrix[$persons];
        }

        $price_1 = get_post_meta($product_id, '_hotel_price_1_person', true);
        $price_2 = get_post_meta($product_id, '_hotel_price_2_persons', true);
        if ($price_1 !== '' && $price_2 !== '') {
            $key = $persons >= 2 ? '_hotel_price_2_persons' : '_hotel_price_1_person';
            return (float) get_post_meta($product_id, $key, true);
        }

        $surcharge = get_post_meta($product_id, '_hotel_extra_person_price', true);
        if ($surcharge !== '' && (float) $surcharge > 0) {
            $extras = max(0, $persons - 1);
            return (float) $product->get_price() + $extras * (float) $surcharge;
        }

        return (float) $product->get_price();
    }

    /**
     * Parse a `count:price` matrix string into an array<int $persons, float $price>.
     *
     * Accepts newlines or semicolons as entry separators and either ":" or
     * "=" as the count/price separator. Whitespace and a trailing currency
     * symbol (€, EUR, $, …) are stripped, and a decimal comma in the price is
     * normalised to a dot. (Comma is NOT used as an entry separator on
     * purpose so that prices like "180,5" stay intact.) Examples that all
     * parse the same way:
     *
     *   "1:100; 2:180; 3:250; 4:320"
     *   "1 = 100\n2 = 180,5\n3 = 250 €"
     *
     * Invalid entries are silently dropped. An empty input yields an empty
     * array, signalling the resolver to fall through to the next strategy.
     *
     * @return array<int, float>
     */
    private function parsePriceByPersonCount(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $entries = preg_split('/[\r\n;]+/', $raw) ?: [];
        $matrix = [];
        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '' || !preg_match('/^([^:=]+)[:=](.+)$/', $entry, $m)) {
                continue;
            }
            $count = (int) trim($m[1]);
            if ($count < 1) {
                continue;
            }
            $price_raw = trim($m[2]);
            $price_raw = preg_replace('/[^\d.,\-]/', '', $price_raw);
            $price_raw = str_replace(',', '.', (string) $price_raw);
            if ($price_raw === '' || !is_numeric($price_raw)) {
                continue;
            }
            $matrix[$count] = (float) $price_raw;
        }
        return $matrix;
    }

    // =========================================================================
    // Checkout fields
    // =========================================================================

    public function addCheckoutFields($fields)
    {
        if (!$this->isActiveForCart()) {
            return $fields;
        }

        $nights = $this->getAvailableNights();
        if (empty($nights)) {
            return $fields;
        }

        $rows = $this->getRowsFromSession();
        $master_active = !empty($rows);

        // Single "I'm interested in accommodation" toggle. Replaces the
        // previous per-night blocks: when on, the customer adds rooms one
        // at a time via the repeater below; when off, the repeater hides.
        $fields['billing']['hotel_master_interested'] = [
            'type' => 'checkbox',
            'label' => Settings::getValue('hotel_selection.accommodation_label'),
            'required' => false,
            'class' => [
                'form-row-wide',
                'js-hotel-master',
                'hotel-master-row',
            ],
            'priority' => 88,
            'default' => $master_active ? 1 : 0,
        ];

        // Repeater placeholder — rendered by renderRepeaterField() via the
        // woocommerce_form_field_hotel_repeater filter. Sits right after
        // the master checkbox.
        $fields['billing']['hotel_rooms'] = [
            'type' => 'hotel_repeater',
            'label' => '',
            'required' => false,
            'class' => array_filter([
                'form-row-wide',
                'hotel-repeater-row',
                $master_active ? '' : 'hotel-field--hidden',
            ]),
            'priority' => 89,
        ];

        return $fields;
    }

    /**
     * Custom WC field type — renders the repeater UI (add / remove rows for
     * accommodation bookings) inline in the billing column. Each row submits
     * as hotel_rooms[N][...].
     *
     * @param string $field Default-rendered HTML (ignored — replaced wholly).
     * @param string $key Field key ("hotel_rooms").
     * @param array  $args Field args from addCheckoutFields().
     * @param mixed  $value Posted value (unused — repeater carries its own).
     */
    public function renderRepeaterField($field, $key, $args, $value)
    {
        $nights = $this->getAvailableNights();
        if (empty($nights)) {
            return $field;
        }

        $rows = $this->getRowsFromSession();
        $effective_max = $this->getEffectiveMaxPersons();

        // Night options + per-night product map for the dropdowns. The JS
        // populates room options from this when a row's night changes.
        $night_options = [];
        foreach ($nights as $night_slug => $night_data) {
            if (empty($night_data['products'])) {
                continue;
            }
            $products = [];
            foreach ($night_data['products'] as $pid => $product) {
                if (!$product instanceof \WC_Product) {
                    continue;
                }
                $stock = '';
                if ($product->managing_stock()) {
                    $stock = ' (' . sprintf(
                        __('%d available', 'alttag-registrations'),
                        (int) $product->get_stock_quantity()
                    ) . ')';
                }
                $min_persons = $this->getProductMinPersons($product);
                $max_persons = $this->getProductMaxPersons($product);
                $products[] = [
                    'id' => (int) $pid,
                    'label' => $product->get_name() . $stock
                        . ' — ' . $this->buildRoomPriceLabel($product, $min_persons, $max_persons),
                    'min' => $min_persons,
                    'max' => $max_persons,
                ];
            }
            $night_options[] = [
                'slug' => $night_slug,
                'label' => $night_data['label'],
                'products' => $products,
            ];
        }

        $row_class = is_array($args['class'] ?? null) ? implode(' ', $args['class']) : '';

        $person_labels = [];
        for ($i = 1; $i <= $effective_max; $i++) {
            $person_labels[(string) $i] = $this->formatPersonsLabel($i);
        }

        $labels = [
            'selectNight' => __('— select a night —', 'alttag-registrations'),
            'selectRoom' => __('— select a room —', 'alttag-registrations'),
            'addRow' => __('+ Add accommodation', 'alttag-registrations'),
            'removeRow' => __('Remove this accommodation', 'alttag-registrations'),
            'nightLabel' => __('Night', 'alttag-registrations'),
            'roomLabel' => Settings::getValue('hotel_selection.room_type_label'),
            'personsLabel' => Settings::getValue('hotel_selection.persons_label'),
            // Short placeholders — the full settings labels ("Meno druhého
            // ubytovaného hosťa") overflow inside narrow repeater columns,
            // and the per-guest heading already gives the context.
            'guestFirstName' => __('First name', 'alttag-registrations'),
            'guestLastName' => __('Last name', 'alttag-registrations'),
            'guestEmail' => __('Email', 'alttag-registrations'),
            'firstGuestHeading' => __('First guest', 'alttag-registrations'),
            'secondGuestHeading' => __('Second guest', 'alttag-registrations'),
            /* translators: %d is the guest ordinal (3, 4, …) */
            'guestHeading' => __('Guest %d', 'alttag-registrations'),
        ];

        $config = [
            'nights' => $night_options,
            'effectiveMax' => $effective_max,
            'personLabels' => $person_labels,
            'labels' => $labels,
        ];

        // Pre-render an empty row HTML used as the JS "Add" template. PHP
        // owns all the labels/options, JS just clones the markup and tweaks
        // name="" attributes with the new row index.
        $template_html = $this->renderRepeaterRowHtml(
            -1, // sentinel index; JS rewrites name attributes on clone
            [],
            $night_options,
            $effective_max,
            $labels
        );

        ob_start();
        ?>
        <p class="form-row <?php echo esc_attr($row_class); ?>" id="<?php echo esc_attr($key); ?>_field">
            <span class="woocommerce-input-wrapper">
                <span class="hotel-repeater"
                      data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
                    <span class="hotel-repeater-rows">
                        <?php
                        $idx = 0;
        foreach ($rows as $row) {
            echo $this->renderRepeaterRowHtml($idx, $row, $night_options, $effective_max, $labels);
            $idx++;
        }
        ?>
                    </span>
                    <button type="button" class="hotel-repeater-add">
                        <?php echo esc_html($labels['addRow']); ?>
                    </button>
                    <script type="text/html" class="hotel-repeater-template">
                        <?php echo $template_html; ?>
                    </script>
                </span>
            </span>
        </p>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a single repeater row's HTML — used for the initial server-side
     * render when the session already has rows. The JS clones rendered rows
     * to produce new ones with empty values.
     */
    private function renderRepeaterRowHtml(
        int $idx,
        array $row,
        array $night_options,
        int $effective_max,
        array $labels
    ): string {
        $row_night = (string) ($row['night'] ?? '');
        $row_product = (int) ($row['product_id'] ?? 0);
        $row_persons = (int) ($row['person_count'] ?? 0);
        $row_guests = is_array($row['extra_guests'] ?? null) ? $row['extra_guests'] : [];

        // Pre-filter products to those available for the chosen night so the
        // initial server render is consistent with what JS would produce.
        $night_products = [];
        foreach ($night_options as $night) {
            if ($night['slug'] === $row_night) {
                $night_products = $night['products'];
                break;
            }
        }

        $min = 1;
        $max = $effective_max;
        foreach ($night_products as $product) {
            if ((int) $product['id'] === $row_product) {
                $min = (int) $product['min'];
                $max = (int) $product['max'];
                break;
            }
        }

        ob_start();
        ?>
        <span class="hotel-repeater-row" data-row-index="<?php echo (int) $idx; ?>">
            <span class="hotel-repeater-row-header">
                <span class="hotel-repeater-cell hotel-repeater-cell-night">
                    <label><?php echo esc_html($labels['nightLabel']); ?></label>
                    <select class="hotel-repeater-night"
                            name="hotel_rooms[<?php echo (int) $idx; ?>][night]">
                        <option value=""><?php echo esc_html($labels['selectNight']); ?></option>
                        <?php foreach ($night_options as $night) : ?>
                            <option value="<?php echo esc_attr($night['slug']); ?>"
                                <?php selected($row_night, $night['slug']); ?>>
                                <?php echo esc_html($night['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </span>
                <span class="hotel-repeater-cell hotel-repeater-cell-product">
                    <label><?php echo esc_html($labels['roomLabel']); ?></label>
                    <select class="hotel-repeater-product"
                            name="hotel_rooms[<?php echo (int) $idx; ?>][product_id]">
                        <option value=""><?php echo esc_html($labels['selectRoom']); ?></option>
                        <?php foreach ($night_products as $product) : ?>
                            <option value="<?php echo (int) $product['id']; ?>"
                                <?php selected($row_product, $product['id']); ?>>
                                <?php echo esc_html($product['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </span>
                <span class="hotel-repeater-cell hotel-repeater-cell-persons <?php echo $row_product > 0 ? '' : 'hotel-field--hidden'; ?>">
                    <label><?php echo esc_html($labels['personsLabel']); ?></label>
                    <select class="hotel-repeater-persons"
                            name="hotel_rooms[<?php echo (int) $idx; ?>][persons]">
                        <?php if ($row_product > 0) : ?>
                            <?php for ($p = $min; $p <= $max; $p++) : ?>
                                <option value="<?php echo (int) $p; ?>"
                                    <?php selected($row_persons, $p); ?>>
                                    <?php echo esc_html($this->formatPersonsLabel($p)); ?>
                                </option>
                            <?php endfor; ?>
                        <?php endif; ?>
                    </select>
                </span>
                <button type="button" class="hotel-repeater-remove"
                        title="<?php echo esc_attr($labels['removeRow']); ?>"
                        aria-label="<?php echo esc_attr($labels['removeRow']); ?>">×</button>
            </span>
            <span class="hotel-repeater-guests <?php echo $row_persons >= 1 ? '' : 'hotel-field--hidden'; ?>">
                <?php
                // Render a guest block per person (1..N). Storage uses a
                // unified hotel_rooms[N][guests][G][...] shape; legacy
                // guest_first_name/extra_guests fields are populated as
                // mirrors by extractRowsFromPost() so downstream readers
                // don't need to change.
                $row_guests_new = is_array($row['guests'] ?? null) ? $row['guests'] : [];
        for ($g = 1; $g <= $effective_max; $g++) {
            // Source priority: new unified guests[] → legacy slots.
            if (isset($row_guests_new[$g]) && is_array($row_guests_new[$g])) {
                $first = (string) ($row_guests_new[$g]['first_name'] ?? '');
                $last = (string) ($row_guests_new[$g]['last_name'] ?? '');
                $email = (string) ($row_guests_new[$g]['email'] ?? '');
            } elseif ($g === 2) {
                $first = (string) ($row['guest_first_name'] ?? '');
                $last = (string) ($row['guest_last_name'] ?? '');
                $email = (string) ($row['guest_email'] ?? '');
            } elseif ($g >= 3 && isset($row_guests[$g])) {
                $first = (string) ($row_guests[$g]['first_name'] ?? '');
                $last = (string) ($row_guests[$g]['last_name'] ?? '');
                $email = (string) ($row_guests[$g]['email'] ?? '');
            } else {
                $first = $last = $email = '';
            }

            $first_name_field = "hotel_rooms[{$idx}][guests][{$g}][first_name]";
            $last_name_field = "hotel_rooms[{$idx}][guests][{$g}][last_name]";
            $email_field = "hotel_rooms[{$idx}][guests][{$g}][email]";

            if ($g === 1) {
                $heading = $labels['firstGuestHeading'];
            } elseif ($g === 2) {
                $heading = $labels['secondGuestHeading'];
            } else {
                $heading = sprintf($labels['guestHeading'], $g);
            }
            $guest_visible = $row_persons >= $g ? '' : 'hotel-field--hidden';
            ?>
                    <span class="hotel-repeater-guest <?php echo esc_attr($guest_visible); ?>"
                          data-guest-index="<?php echo (int) $g; ?>">
                        <span class="hotel-repeater-guest-heading"><?php echo esc_html($heading); ?></span>
                        <span class="hotel-repeater-guest-fields">
                            <input type="text"
                                   name="<?php echo esc_attr($first_name_field); ?>"
                                   value="<?php echo esc_attr($first); ?>"
                                   placeholder="<?php echo esc_attr($labels['guestFirstName']); ?>"
                                   class="hotel-repeater-guest-first">
                            <input type="text"
                                   name="<?php echo esc_attr($last_name_field); ?>"
                                   value="<?php echo esc_attr($last); ?>"
                                   placeholder="<?php echo esc_attr($labels['guestLastName']); ?>"
                                   class="hotel-repeater-guest-last">
                            <input type="email"
                                   name="<?php echo esc_attr($email_field); ?>"
                                   value="<?php echo esc_attr($email); ?>"
                                   placeholder="<?php echo esc_attr($labels['guestEmail']); ?>"
                                   class="hotel-repeater-guest-email">
                        </span>
                    </span>
                    <?php
        }
        ?>
            </span>
        </span>
        <?php
        return ob_get_clean();
    }

    public function validateFields($data, $errors)
    {
        if (!$this->isActiveForCart()) {
            return;
        }

        $nights = $this->getAvailableNights();
        if (empty($nights)) {
            return;
        }

        $master = isset($_POST['hotel_master_interested'])
            && $_POST['hotel_master_interested'] === '1';
        if (!$master) {
            return;
        }

        $raw = $_POST['hotel_rooms'] ?? [];
        if (!is_array($raw) || empty($raw)) {
            $errors->add(
                'hotel_no_room_selected',
                __('Please add at least one accommodation or uncheck "I am interested in accommodation".', 'alttag-registrations')
            );
            return;
        }

        $valid_count = 0;
        foreach ($raw as $idx => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $night_slug = isset($entry['night'])
                ? sanitize_text_field(wp_unslash((string) $entry['night']))
                : '';
            $product_id = isset($entry['product_id']) ? (int) $entry['product_id'] : 0;

            // Wholly empty rows (no night and no product) are silently ignored
            // so a customer who clicked "Add" but didn't pick anything yet
            // doesn't get blocked.
            if ($night_slug === '' && $product_id === 0) {
                continue;
            }

            if ($night_slug === '' || !isset($nights[$night_slug])) {
                $errors->add(
                    "hotel_night_invalid_{$idx}",
                    __('Please select a night for each accommodation row.', 'alttag-registrations')
                );
                continue;
            }

            if ($product_id <= 0 || !isset($nights[$night_slug]['products'][$product_id])) {
                $errors->add(
                    "hotel_product_invalid_{$idx}",
                    __('Please select a valid room for each accommodation row.', 'alttag-registrations')
                );
                continue;
            }

            $product = $nights[$night_slug]['products'][$product_id];
            if ($product instanceof \WC_Product) {
                $min = $this->getProductMinPersons($product);
                $max = $this->getProductMaxPersons($product);
                if ($min < $max) {
                    $persons = isset($entry['persons']) ? (int) $entry['persons'] : 0;
                    if ($persons < $min || $persons > $max) {
                        $errors->add(
                            "hotel_persons_required_{$idx}",
                            __('Please select the number of persons.', 'alttag-registrations')
                        );
                        continue;
                    }
                }
            }

            $valid_count++;
        }

        if ($valid_count === 0) {
            $errors->add(
                'hotel_no_valid_room',
                __('Please add at least one accommodation or uncheck "I am interested in accommodation".', 'alttag-registrations')
            );
        }
    }

    // =========================================================================
    // AJAX cart update + custom price
    // =========================================================================

    public function handleCartUpdate()
    {
        check_ajax_referer('alttag_hotel_cart', 'nonce');

        if (!$this->isActiveForCart()) {
            wp_send_json_success(['skipped' => true]);
            return;
        }

        $nights = $this->getAvailableNights();
        if (empty($nights)) {
            wp_send_json_success(['skipped' => true]);
            return;
        }

        // Remove every existing hotel cart item — they will be re-added below
        // from the freshly posted repeater rows. Matching by the alttag_hotel
        // marker (instead of product_id) so renaming a hotel product
        // mid-session never leaves orphan rows in the cart.
        foreach (WC()->cart->get_cart() as $key => $item) {
            if (!empty($item['alttag_hotel'])) {
                WC()->cart->remove_cart_item($key);
            }
        }

        $rows = $this->extractRowsFromPost();

        // Persist as a numeric list — that's what readers
        // (saveSelectionToOrder, getRowsFromSession) expect now. The legacy
        // single-selection mirror is kept for any back-compat reader still
        // looking at SESSION_KEY.
        if (!empty($rows)) {
            WC()->session->set(self::SESSIONS_KEY, $rows);
            WC()->session->set(self::SESSION_KEY, $rows[0]);
        } else {
            WC()->session->__unset(self::SESSIONS_KEY);
            WC()->session->__unset(self::SESSION_KEY);
        }

        // Add one cart line per repeater row.
        foreach ($rows as $idx => $sel) {
            $product = $nights[$sel['night']]['products'][$sel['product_id']] ?? null;
            if (!$product instanceof \WC_Product) {
                continue;
            }
            $person_count = (int) $sel['person_count'];
            if ($person_count < 1) {
                $person_count = $this->getProductMinPersons($product);
            }
            $custom_price = $this->getProductPriceForPersons($product, $person_count);

            $cart_item_data = [
                'alttag_hotel' => true,
                'alttag_hotel_night' => $sel['night'],
                'alttag_hotel_night_index' => $idx,
                'alttag_hotel_persons' => $person_count,
                // New unified guests array — display reads this first so
                // guest #1 is shown alongside the others. Legacy single
                // guest_name / extra_guests still populated for back-compat.
                'alttag_hotel_guests' => $sel['guests'] ?? [],
                'alttag_hotel_guest_name' => $sel['guest_name'],
                'alttag_hotel_guest_email' => $sel['guest_email'],
                'alttag_hotel_extra_guests' => $sel['extra_guests'],
                'alttag_hotel_custom_price' => $custom_price,
                // unique_key includes the row index so two rows for the same
                // night with identical guests still produce distinct cart
                // lines (WC otherwise dedupes by hash of cart_item_data).
                'unique_key' => md5(microtime() . wp_rand() . $sel['night'] . $idx),
            ];

            WC()->cart->add_to_cart((int) $sel['product_id'], 1, 0, [], $cart_item_data);
        }

        wp_send_json_success([
            'fragments' => apply_filters('woocommerce_update_order_review_fragments', []),
        ]);
    }

    public function applyCustomCartPrice($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item) {
            if (!empty($cart_item['alttag_hotel']) && isset($cart_item['alttag_hotel_custom_price'])) {
                $cart_item['data']->set_price($cart_item['alttag_hotel_custom_price']);
            }
        }
    }

    public function renderCartItemMeta($item_data, $cart_item)
    {
        if (empty($cart_item['alttag_hotel'])) {
            return $item_data;
        }

        // Prefix the cart row with its night label when a multi-night setup
        // is in play, so two Dvojlôžková rooms (different nights) don't look
        // identical in the order review.
        $night_slug = (string) ($cart_item['alttag_hotel_night'] ?? '');
        if ($night_slug !== '') {
            $nights = $this->getAvailableNights();
            if (count($nights) > 1 && isset($nights[$night_slug]['label'])) {
                $item_data[] = [
                    'name' => esc_html__('Night', 'alttag-registrations'),
                    'value' => esc_html($nights[$night_slug]['label']),
                ];
            }
        }

        $persons = (int) ($cart_item['alttag_hotel_persons'] ?? 0);
        if ($persons > 0) {
            $item_data[] = [
                'name' => Settings::getValue('hotel_selection.persons_label'),
                'value' => $this->formatPersonsLabel($persons),
            ];
        }

        // Price breakdown — uses the configured price resolver so it works
        // for any strategy (matrix, explicit 1-/2-person, surcharge, flat).
        // We display the base (1-person) price plus the per-guest incremental
        // cost (cost(N) − cost(N − 1)) for each additional guest.
        $product_id = (int) ($cart_item['product_id'] ?? 0);
        $product = $product_id ? wc_get_product($product_id) : null;
        $per_guest_extra = [];
        if ($persons > 0 && $product instanceof \WC_Product) {
            $base = $this->getProductPriceForPersons($product, 1);
            $item_data[] = [
                'name' => __('Base price', 'alttag-registrations'),
                'value' => wc_price($base),
            ];
            $previous = $base;
            for ($i = 2; $i <= $persons; $i++) {
                $current = $this->getProductPriceForPersons($product, $i);
                $diff = $current - $previous;
                if ($diff > 0) {
                    $per_guest_extra[$i] = $diff;
                }
                $previous = $current;
            }
        }

        // Unified guests array — when present, iterate it (1..N) so the
        // first guest of each room is surfaced too. Old carts without the
        // array fall back to the legacy "guest 2 + extras" rendering.
        $unified_guests = $cart_item['alttag_hotel_guests'] ?? null;
        if (is_array($unified_guests) && !empty($unified_guests)) {
            for ($idx = 1; $idx <= $persons; $idx++) {
                $g = $unified_guests[$idx] ?? null;
                if (!is_array($g)) {
                    continue;
                }
                $name = (string) ($g['name'] ?? trim(((string) ($g['first_name'] ?? '')) . ' ' . ((string) ($g['last_name'] ?? ''))));
                $row = $this->buildGuestSummaryRow(
                    $idx,
                    $name,
                    (string) ($g['email'] ?? ''),
                    $per_guest_extra[$idx] ?? null
                );
                if ($row !== null) {
                    $item_data[] = $row;
                }
            }
            return $item_data;
        }

        // Legacy fallback (carts saved before the unified guests refactor).
        if ($persons >= 2) {
            $row = $this->buildGuestSummaryRow(
                2,
                (string) ($cart_item['alttag_hotel_guest_name'] ?? ''),
                (string) ($cart_item['alttag_hotel_guest_email'] ?? ''),
                $per_guest_extra[2] ?? null
            );
            if ($row !== null) {
                $item_data[] = $row;
            }
        }

        $extra_guests = $cart_item['alttag_hotel_extra_guests'] ?? [];
        if (is_array($extra_guests)) {
            foreach ($extra_guests as $person_index => $guest) {
                $idx = (int) $person_index;
                if ($idx > $persons) {
                    continue;
                }
                $row = $this->buildGuestSummaryRow(
                    $idx,
                    (string) ($guest['name'] ?? ''),
                    (string) ($guest['email'] ?? ''),
                    $per_guest_extra[$idx] ?? null
                );
                if ($row !== null) {
                    $item_data[] = $row;
                }
            }
        }

        return $item_data;
    }

    /**
     * Build a single ['name' => label, 'value' => combined] row for a guest
     * so the cart summary stays compact: one row per guest with name and
     * email on the same line, and — when surcharge pricing is active — the
     * per-guest surcharge appended after the contact info. Returns null when
     * the guest has neither name nor email (skip).
     *
     * @return array{name: string, value: string}|null
     */
    private function buildGuestSummaryRow(
        int $person_index,
        string $name,
        string $email,
        ?float $extra_cost = null
    ): ?array {
        $name = trim($name);
        $email = trim($email);
        if ($name === '' && $email === '') {
            return null;
        }

        $contact = '';
        if ($name !== '' && $email !== '') {
            $contact = sprintf(
                /* translators: 1: guest full name, 2: guest email */
                __('%1$s (%2$s)', 'alttag-registrations'),
                esc_html($name),
                esc_html($email)
            );
        } else {
            $contact = esc_html($name !== '' ? $name : $email);
        }

        if ($extra_cost !== null && $extra_cost > 0) {
            $contact = sprintf(
                /* translators: 1: guest contact info, 2: incremental cost added by this guest */
                __('%1$s — +%2$s', 'alttag-registrations'),
                $contact,
                wc_price($extra_cost)
            );
        }

        return [
            'name' => $this->guestSummaryLabel($person_index),
            'value' => $contact,
        ];
    }

    /**
     * Compact label like "Guest 2" / "2. hosť" for use in summary lists where
     * a full "Name and surname of guest %d" would be too long.
     */
    private function guestSummaryLabel(int $person_index): string
    {
        /* translators: %d is the guest ordinal (2, 3, 4, …) */
        return sprintf(__('Guest %d', 'alttag-registrations'), $person_index);
    }

    /**
     * Long-form labels still used by the order email and thank-you page where
     * the layout has more vertical room.
     */
    private function guestNameLabel(int $person_index): string
    {
        /* translators: %d is the guest ordinal (2, 3, 4, …) */
        return sprintf(__('Name and surname of guest %d', 'alttag-registrations'), $person_index);
    }

    private function guestEmailLabel(int $person_index): string
    {
        /* translators: %d is the guest ordinal (2, 3, 4, …) */
        return sprintf(__('Email of guest %d', 'alttag-registrations'), $person_index);
    }

    // =========================================================================
    // Persistence
    // =========================================================================

    public function saveLineItemMeta($item, $cart_item_key, $values, $order)
    {
        if (empty($values['alttag_hotel'])) {
            return;
        }

        $persons = (int) ($values['alttag_hotel_persons'] ?? 0);
        if ($persons > 0) {
            $item->add_meta_data(
                Settings::getValue('hotel_selection.persons_label'),
                $this->formatPersonsLabel($persons)
            );
            $item->add_meta_data('_hotel_person_count', $persons, true);
        }

        // Public, compact "Guest 2 / 3 / 4: Name (email)" rows so the invoice
        // line description and admin order list show one tidy line per guest
        // instead of a verbose Name + Email pair per guest. Raw values stay
        // available in hidden meta for programmatic consumers.
        $guest_name = $this->resolveGuestName($values);
        $guest_email = $this->resolveGuestEmail($values);
        if ($guest_name !== '') {
            $item->add_meta_data('_hotel_guest_name', $guest_name, true);
        }
        if ($guest_email !== '') {
            $item->add_meta_data('_hotel_guest_email', $guest_email, true);
        }
        if ($persons >= 2 && ($guest_name !== '' || $guest_email !== '')) {
            $row = $this->buildGuestSummaryRow(2, $guest_name, $guest_email);
            if ($row !== null) {
                $item->add_meta_data($row['name'], $row['value']);
            }
        }

        $extra_guests = $this->resolveExtraGuests($values);
        if (!empty($extra_guests)) {
            $item->add_meta_data('_hotel_extra_guests', $extra_guests, true);
            foreach ($extra_guests as $person_index => $guest) {
                $idx = (int) $person_index;
                $row = $this->buildGuestSummaryRow(
                    $idx,
                    (string) ($guest['name'] ?? ''),
                    (string) ($guest['email'] ?? '')
                );
                if ($row !== null) {
                    $item->add_meta_data($row['name'], $row['value']);
                }
            }
        }
    }

    public function hideRawItemMeta($keys)
    {
        $keys[] = '_hotel_person_count';
        $keys[] = '_hotel_guest_name';
        $keys[] = '_hotel_guest_email';
        $keys[] = '_hotel_extra_guests';
        return $keys;
    }

    public function saveSelectionToOrder($order_id)
    {
        if (empty($this->getAvailableNights())) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Single source of truth — same extraction used by handleCartUpdate
        // so what's in the cart matches what gets persisted on the order.
        $rows = $this->extractRowsFromPost();

        if (empty($rows)) {
            // Clear any stale meta from a previous selection attempt.
            $order->delete_meta_data(self::ORDER_META_NIGHTS_KEY);
            $order->delete_meta_data(self::ORDER_META_KEY);
            $order->save();
            if (WC()->session) {
                WC()->session->__unset(self::SESSION_KEY);
                WC()->session->__unset(self::SESSIONS_KEY);
            }
            return;
        }

        // Strip the transient 'accommodation' flag — it was only useful while
        // routing data through legacy code paths. Each persisted entry keeps
        // its 'night' slug as the canonical night identifier.
        $persisted = [];
        foreach ($rows as $row) {
            unset($row['accommodation']);
            $persisted[] = $row;
        }

        $order->update_meta_data(self::ORDER_META_NIGHTS_KEY, $persisted);
        // Legacy mirror — first entry mimicked the pre-refactor single
        // selection shape; downstream readers gracefully fall back to it.
        $order->update_meta_data(self::ORDER_META_KEY, $persisted[0]);
        $order->save();

        if (WC()->session) {
            WC()->session->__unset(self::SESSION_KEY);
            WC()->session->__unset(self::SESSIONS_KEY);
        }
    }

    public function saveSelectionToParticipant($participant_id, $order_data)
    {
        if (empty($order_data['order_id'])) {
            return;
        }

        $order = wc_get_order((int) $order_data['order_id']);
        if (!$order) {
            return;
        }

        // Prefer the new multi-night payload; fall back to the legacy single
        // selection so participants registered before the refactor keep
        // displaying meaningful info.
        $nights_meta = $order->get_meta(self::ORDER_META_NIGHTS_KEY);
        if (!is_array($nights_meta) || empty($nights_meta)) {
            $legacy = $order->get_meta(self::ORDER_META_KEY);
            if (is_array($legacy) && !empty($legacy['product_id'])) {
                $nights_meta = ['legacy' => $legacy];
            }
        }
        if (empty($nights_meta)) {
            return;
        }

        // Full payload — admin meta box and verify page iterate this.
        update_post_meta($participant_id, 'hotel_selections', $nights_meta);

        // Flat list of every booked product ID, one row per night, so the
        // admin filter dropdown can match multi-night participants without
        // unserialising hotel_selections. Wiped and re-populated on each save.
        delete_post_meta($participant_id, 'hotel_booked_product_id');
        foreach ($nights_meta as $night_sel) {
            if (is_array($night_sel) && !empty($night_sel['product_id'])) {
                add_post_meta($participant_id, 'hotel_booked_product_id', (int) $night_sel['product_id']);
            }
        }

        // Flat back-compat keys mirror the FIRST night's selection so admin
        // listing columns, exports, and any third-party reader that still
        // looks for hotel_product_name / hotel_person_count keeps working.
        $first = reset($nights_meta);
        $product_name = (string) ($first['product_name'] ?? '');
        if ($product_name === '' && !empty($first['product_id'])) {
            $product = wc_get_product((int) $first['product_id']);
            if ($product) {
                $product_name = $product->get_name();
            }
        }
        update_post_meta($participant_id, 'hotel_product_id', (int) ($first['product_id'] ?? 0));
        update_post_meta($participant_id, 'hotel_product_name', $product_name);
        update_post_meta($participant_id, 'hotel_person_count', (string) ($first['person_count'] ?? ''));
        update_post_meta($participant_id, 'hotel_guest_name', (string) ($first['guest_name'] ?? ''));
        update_post_meta($participant_id, 'hotel_guest_email', (string) ($first['guest_email'] ?? ''));

        $first_extras = is_array($first['extra_guests'] ?? null) ? $first['extra_guests'] : [];
        update_post_meta($participant_id, 'hotel_extra_guests', $first_extras);

        // Flat per-guest rows derived from the first night — admin meta box
        // sections still render guest 2..N via post_meta lookup.
        $guest_2_value = $this->formatGuestRowValue(
            (string) ($first['guest_name'] ?? ''),
            (string) ($first['guest_email'] ?? '')
        );
        update_post_meta($participant_id, 'hotel_guest_2', $guest_2_value);

        foreach ($first_extras as $idx => $guest) {
            $idx = (int) $idx;
            $value = $this->formatGuestRowValue(
                (string) ($guest['name'] ?? ''),
                (string) ($guest['email'] ?? '')
            );
            update_post_meta($participant_id, "hotel_guest_{$idx}", $value);
        }
    }

    /**
     * Combine name and email into a single compact display value for
     * post-meta-driven contexts (admin meta box, verification page).
     */
    private function formatGuestRowValue(string $name, string $email): string
    {
        $name = trim($name);
        $email = trim($email);
        if ($name === '' && $email === '') {
            return '';
        }
        if ($name !== '' && $email !== '') {
            return $name . ' (' . $email . ')';
        }
        return $name !== '' ? $name : $email;
    }

    // =========================================================================
    // Email / Thank-you / Invoice rendering
    // =========================================================================

    /**
     * Render hotel info block in order-processing email (under custom fields).
     */
    public function renderEmailInfo($order)
    {
        $infos = $this->getOrderHotelInfos($order);
        if (empty($infos)) {
            return;
        }

        $primary = apply_filters('alttag_registrations_email_primary_color', '#2B5C63');
        $persons_label = Settings::getValue('hotel_selection.persons_label');
        $multi = count($infos) > 1;

        $row_style = 'width:100%; margin:6px 0; color:' . $primary . '; font-size:14px;'
            . ' font-family:Arial, Helvetica, sans-serif; line-height:1.6;';
        $heading_style = 'width:100%; margin:18px 0 8px; color:' . $primary
            . '; font-size:16px; font-weight:700;'
            . ' font-family:Arial, Helvetica, sans-serif; line-height:1.4;';
        $subheading_style = 'width:100%; margin:14px 0 4px; color:' . $primary
            . '; font-size:13px; font-weight:600; text-transform:uppercase;'
            . ' letter-spacing:0.04em; opacity:0.8;'
            . ' font-family:Arial, Helvetica, sans-serif; line-height:1.4;';

        echo '<div style="margin:14px 0;">';
        echo '<div style="' . $heading_style . '">'
            . esc_html__('Accommodation', 'alttag-registrations') . '</div>';

        $notice_guest_names = [];
        foreach ($infos as $info) {
            if ($multi && !empty($info['night_label'])) {
                echo '<div style="' . $subheading_style . '">' . esc_html($info['night_label']) . '</div>';
            }
            echo '<div style="' . $row_style . '"><strong>' . esc_html($info['hotel_name']) . '</strong></div>';
            if ($info['persons'] > 0) {
                echo '<div style="' . $row_style . '">'
                    . esc_html($persons_label) . ': '
                    . esc_html($this->formatPersonsLabel($info['persons']))
                    . '</div>';
            }
            // Unified guests rendering — iterates 1..persons, populated
            // for both new (canonical guests[]) and legacy orders (via
            // synthesizeGuestsArray in getOrderHotelInfos). Guest #1 is now
            // explicit per room since the buyer can book multiple rooms
            // under different names.
            $guests = is_array($info['guests'] ?? null) ? $info['guests'] : [];
            for ($idx = 1; $idx <= (int) $info['persons']; $idx++) {
                $guest = $guests[$idx] ?? null;
                if (!is_array($guest)) {
                    continue;
                }
                $name = trim((string) ($guest['name'] ?? ''));
                $email = trim((string) ($guest['email'] ?? ''));
                if ($name !== '') {
                    echo '<div style="' . $row_style . '">'
                        . esc_html($this->guestNameLabel($idx)) . ': '
                        . esc_html($name)
                        . '</div>';
                    // Only guests beyond #1 trigger the "register separately"
                    // notice — guest #1 is typically the buyer themselves.
                    if ($idx >= 2) {
                        $notice_guest_names[$name] = true;
                    }
                }
                if ($email !== '') {
                    echo '<div style="' . $row_style . '">'
                        . esc_html($this->guestEmailLabel($idx)) . ': '
                        . esc_html($email)
                        . '</div>';
                }
            }
        }

        $notice_names = array_keys($notice_guest_names);
        $registration_url = $this->getGuestRegistrationUrl();

        // Default reminder for paid hotel guests — shown in the order email
        // whenever there's at least one extra guest across all nights. Site
        // customizations can append more notices via the action below.
        if (!empty($notice_names)) {
            $link_open = '<a href="' . esc_url($registration_url) . '" target="_blank"'
                . ' style="color:#856404 !important; font-weight:700; text-decoration:underline;">';
            $link_close = '</a>';
            echo '<div style="width:100%; margin:15px 0 0; padding:15px; background-color:#fff3cd;'
                . ' box-sizing:border-box; border-left:4px solid #ffc107; border-radius:6px;'
                . ' font-size:14px; font-family:Arial, Helvetica, sans-serif; line-height:1.6;">';
            echo '<strong style="color:#856404 !important;">'
                . esc_html__('Important notice:', 'alttag-registrations') . '</strong><br>';
            echo '<span style="display:block; margin-top:6px; color:#856404 !important;">';
            echo $this->renderGuestNoticeBody($notice_names, $link_open, $link_close);
            echo '</span>';
            echo '</div>';
        }

        /**
         * @param array  $infos              Per-night info list from getOrderHotelInfos.
         * @param array  $notice_names       De-duplicated guest names that triggered the reminder.
         * @param string $registration_url   Configured registration URL fallback.
         */
        do_action('alttag_hotel_email_after_info', $infos, $notice_names, $registration_url);
        echo '</div>';
    }

    /**
     * Build the localized "if interested in attending" sentence for one or
     * more guests with proper Slovak grammar (singular vs plural). Names are
     * escaped before being joined.
     */
    private function renderGuestNoticeBody(array $names, string $link_open, string $link_close): string
    {
        $count = count($names);
        $escaped = array_map('esc_html', $names);
        $joined = implode(', ', $escaped);

        $template = _n(
            'Guest <strong>%1$s</strong> - if interested in attending the conference, they must buy a ticket separately %2$shere%3$s.',
            'Guests <strong>%1$s</strong> - if interested in attending the conference, they must buy a ticket separately %2$shere%3$s.',
            $count,
            'alttag-registrations'
        );

        $template = wp_kses(
            $template,
            [
                'strong' => [],
                'a' => ['href' => [], 'target' => [], 'style' => []],
            ]
        );

        return sprintf($template, $joined, $link_open, $link_close);
    }

    /**
     * Render hotel info block on thank-you page (under download links). When
     * the order spans multiple nights, each gets its own card with a small
     * night label header.
     */
    public function renderThankYouInfo($order)
    {
        $infos = $this->getOrderHotelInfos($order);
        if (empty($infos)) {
            return;
        }

        $persons_label = Settings::getValue('hotel_selection.persons_label');
        $multi = count($infos) > 1;
        $box = 'margin:20px 0; padding:20px; background-color:#f0f4f8;'
            . ' border-left:4px solid #3d3d5c; border-radius:4px;';

        $notice_guest_names = [];
        foreach ($infos as $info) {
            echo '<div style="' . $box . '">';
            echo '<p style="margin:0 0 4px 0; text-transform:uppercase; letter-spacing:0.04em;'
                . ' font-size:12px; color:#3d3d5c; opacity:0.75; font-weight:600;">';
            if ($multi && !empty($info['night_label'])) {
                echo esc_html(sprintf(
                    /* translators: 1: section label "Accommodation", 2: night label */
                    __('%1$s — %2$s', 'alttag-registrations'),
                    __('Accommodation', 'alttag-registrations'),
                    $info['night_label']
                ));
            } else {
                echo esc_html__('Accommodation', 'alttag-registrations');
            }
            echo '</p>';
            echo '<p style="margin:0 0 10px 0; font-weight:bold; color:#3d3d5c; font-size:16px;">';
            echo esc_html($info['hotel_name']);
            echo '</p>';

            if ($info['persons'] > 0) {
                echo '<p style="margin:0 0 4px 0; color:#3d3d5c; font-size:14px; line-height:1.6;">';
                echo esc_html($persons_label) . ': ' . esc_html($this->formatPersonsLabel($info['persons']));
                echo '</p>';
            }
            // Unified guests iteration — same shape used by the email
            // template. Guest #1 is now explicit per room.
            $guests = is_array($info['guests'] ?? null) ? $info['guests'] : [];
            for ($idx = 1; $idx <= (int) $info['persons']; $idx++) {
                $guest = $guests[$idx] ?? null;
                if (!is_array($guest)) {
                    continue;
                }
                $name = trim((string) ($guest['name'] ?? ''));
                $email = trim((string) ($guest['email'] ?? ''));
                if ($name !== '') {
                    echo '<p style="margin:0 0 4px 0; color:#3d3d5c; font-size:14px; line-height:1.6;">';
                    echo esc_html($this->guestNameLabel($idx)) . ': ' . esc_html($name);
                    echo '</p>';
                    if ($idx >= 2) {
                        $notice_guest_names[$name] = true;
                    }
                }
                if ($email !== '') {
                    echo '<p style="margin:0 0 4px 0; color:#3d3d5c; font-size:14px; line-height:1.6;">';
                    echo esc_html($this->guestEmailLabel($idx)) . ': ' . esc_html($email);
                    echo '</p>';
                }
            }
            echo '</div>';
        }

        $notice_names = array_keys($notice_guest_names);
        $registration_url = $this->getGuestRegistrationUrl();

        if (!empty($notice_names)) {
            $link_open = '<a href="' . esc_url($registration_url) . '" target="_blank"'
                . ' style="text-decoration:underline; background:transparent; padding:0;'
                . ' color:#856404 !important; font-weight:700;">';
            $link_close = '</a>';
            echo '<div style="margin:20px 0; padding:20px; background-color:#fff3cd;'
                . ' border-left:4px solid #ffc107; border-radius:4px;">';
            echo '<p style="margin:0 0 10px 0; font-weight:bold; color:#856404 !important; font-size:16px;">';
            echo esc_html__('Important notice:', 'alttag-registrations');
            echo '</p>';
            echo '<p style="margin:0; color:#856404 !important; font-size:14px; line-height:1.6;">';
            echo $this->renderGuestNoticeBody($notice_names, $link_open, $link_close);
            echo '</p>';
            echo '</div>';
        }

        /**
         * @param array  $infos              Per-night info list from getOrderHotelInfos.
         * @param array  $notice_names       De-duplicated guest names that triggered the reminder.
         * @param string $registration_url   Configured registration URL fallback.
         */
        do_action('alttag_hotel_thankyou_after_info', $infos, $notice_names, $registration_url);
    }

    /**
     * Append second-guest info to SuperFaktura invoice comment when 2 persons.
     * Format: "{guest_name_label}: Name <email>"
     */
    public function appendInvoiceGuestInfo($data, $order, $type)
    {
        $info = $this->getOrderHotelInfo($order);
        if (empty($info) || $info['persons'] < 2) {
            return $data;
        }

        $guest_name = $info['guest_name'];
        $guest_email = $info['guest_email'];
        if ($guest_name === '' && $guest_email === '') {
            return $data;
        }

        $guest_name_label = Settings::getValue('hotel_selection.guest_name_label');
        $parts = [];
        if ($guest_name !== '') {
            $parts[] = $guest_name;
        }
        if ($guest_email !== '') {
            $parts[] = '<' . $guest_email . '>';
        }

        $lines = ["\r\n" . $guest_name_label . ': ' . implode(' ', $parts)];

        foreach ($info['extra_guests'] ?? [] as $person_index => $guest) {
            if ((int) $person_index > $info['persons']) {
                continue;
            }
            $name = trim((string) ($guest['name'] ?? ''));
            $email = trim((string) ($guest['email'] ?? ''));
            if ($name === '' && $email === '') {
                continue;
            }
            $extra_parts = [];
            if ($name !== '') {
                $extra_parts[] = $name;
            }
            if ($email !== '') {
                $extra_parts[] = '<' . $email . '>';
            }
            $lines[] = "\r\n" . sprintf('%s (%d.)', $guest_name_label, (int) $person_index)
                . ': ' . implode(' ', $extra_parts);
        }

        $appended = implode('', $lines);
        $data['comment'] = !empty($data['comment']) ? $data['comment'] . $appended : $appended;

        return $data;
    }

    // =========================================================================
    // Second-guest notification email
    // =========================================================================

    /**
     * Send the second hotel guest a notification email after the order is paid:
     * confirms the accommodation booking and tells them the conference ticket
     * must be purchased separately. Reuses the email layout (header / body /
     * yellow notice box / footer) and color filters from the standard plugin
     * email pipeline (EmailWrapper + alttag_registrations_email_*_color).
     *
     * Already-sent flag stored in `_alttag_hotel_guest_notified` order meta to
     * prevent duplicate sends if the order is re-completed.
     */
    public function sendGuestNotification($order_id)
    {
        if (!Settings::getValue('hotel_selection.send_guest_notification')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $infos = $this->getOrderHotelInfos($order);
        if (empty($infos)) {
            return;
        }

        // Collect recipients across every night, de-duplicated by email so a
        // guest staying both nights is only notified once. The buyer's own
        // email is skipped — they already received the order confirmation,
        // so a separate "you're a guest" message would be redundant.
        $buyer_email = strtolower(trim((string) $order->get_billing_email()));
        $recipients = [];
        foreach ($infos as $info) {
            $guests = is_array($info['guests'] ?? null) ? $info['guests'] : [];
            for ($idx = 1; $idx <= (int) $info['persons']; $idx++) {
                $guest = $guests[$idx] ?? null;
                if (!is_array($guest)) {
                    continue;
                }
                $name = trim((string) ($guest['name'] ?? ''));
                $email = trim((string) ($guest['email'] ?? ''));
                if ($name === '' || $email === '') {
                    continue;
                }
                if (strtolower($email) === $buyer_email) {
                    continue;
                }
                $recipients[$email] = ['name' => $name, 'email' => $email];
            }
        }
        $recipients = array_values($recipients);

        if (empty($recipients)) {
            return;
        }

        if ($order->get_meta('_alttag_hotel_guest_notified') === '1') {
            return;
        }

        $event_name = function_exists('Alttag\Registrations\get_event_name')
            ? \Alttag\Registrations\get_event_name() : '';
        $event_dates = function_exists('Alttag\Registrations\get_event_dates_string')
            ? \Alttag\Registrations\get_event_dates_string() : '';

        $registrant_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $registrant_email = $order->get_billing_email();

        $registration_url = $this->getGuestRegistrationUrl();

        $any_sent = false;
        foreach ($recipients as $recipient) {
            $message = $this->buildGuestNotificationMessage(
                $recipient['name'],
                $registrant_name,
                $registrant_email,
                $event_name,
                $event_dates,
                $registration_url
            );

            $sent = wp_mail(
                $recipient['email'],
                $message['subject'],
                $message['content'],
                ['Content-Type: text/html; charset=UTF-8']
            );

            if ($sent) {
                $any_sent = true;
            }
        }

        if ($any_sent) {
            $order->update_meta_data('_alttag_hotel_guest_notified', '1');
            $order->save();
        }
    }

    /**
     * Build the subject + HTML body for the guest-notification email for one
     * recipient. Reuses the EmailWrapper layout and the same color filters as
     * the standard plugin email pipeline.
     */
    private function buildGuestNotificationMessage(
        string $guest_name,
        string $registrant_name,
        string $registrant_email,
        string $event_name,
        string $event_dates,
        string $registration_url
    ): array {
        $placeholders = [
            '{event_name}' => $event_name,
            '{event_dates}' => $event_dates,
            '{hotel_guest_name}' => $guest_name,
            '{registrant_name}' => $registrant_name,
            '{registrant_email}' => $registrant_email,
        ];

        $subject_tpl = (string) Settings::getValue('hotel_selection.guest_notification_subject');
        $subject = $subject_tpl !== ''
            ? strtr($subject_tpl, $placeholders)
            : sprintf(__('Accommodation information - %s', 'alttag-registrations'), $event_name);

        $heading = strtr((string) Settings::getValue('hotel_selection.guest_notification_heading'), $placeholders);
        $intro = strtr((string) Settings::getValue('hotel_selection.guest_notification_intro'), $placeholders);
        $registrant_text_tpl = (string) Settings::getValue('hotel_selection.guest_notification_registrant_text');
        $registrant_text = ($registrant_name !== '' || $registrant_email !== '')
            ? strtr($registrant_text_tpl, $placeholders)
            : '';
        $notice_title = (string) Settings::getValue('hotel_selection.guest_notification_notice_title');
        $notice_body_tpl = (string) Settings::getValue('hotel_selection.guest_notification_notice_body');
        $registration_link = '<a href="' . esc_url($registration_url) . '" style="color:#856404 !important; font-weight:600;'
            . ' text-decoration:underline;">' . esc_html($registration_url) . '</a>';
        $notice_body = strtr($notice_body_tpl, ['{registration_url}' => $registration_link] + $placeholders);
        $outro = strtr((string) Settings::getValue('hotel_selection.guest_notification_outro'), $placeholders);

        $primary = apply_filters('alttag_registrations_email_primary_color', '#3d3d5c');
        $accent = apply_filters('alttag_registrations_email_accent_color', '#FFFFFF');
        $p_style = 'display:block;width:100%;margin:0;padding:15px 30px;font-size:15px;line-height:1.7;color:'
            . $primary . ';font-family:Arial,Helvetica,sans-serif;box-sizing:border-box;';

        ob_start();
        ?>
        <div class="header" style="display:block;width:100%;background:<?php echo esc_attr($primary); ?>;
            padding:40px 30px;text-align:center;border-radius:12px 12px 0 0;box-sizing:border-box;">
            <h1 style="display:block;margin:0;color:<?php echo esc_attr($accent); ?>;font-size:20px;
                font-weight:600;letter-spacing:0.3px;line-height:1.6;">
                <?php echo wp_kses_post($heading); ?>
            </h1>
        </div>
        <div style="display:block;width:100%;background-color:#ffffff;padding:20px 0;box-sizing:border-box;">
            <?php /* translators: 1: guest full name */ ?>
            <p style="<?php echo esc_attr($p_style); ?>">
                <?php echo sprintf(esc_html__('Dear %s,', 'alttag-registrations'), esc_html($guest_name)); ?>
            </p>

            <?php if ($intro !== '') : ?>
                <p style="<?php echo esc_attr($p_style); ?>"><?php echo wp_kses_post($intro); ?></p>
            <?php endif; ?>

            <?php if ($registrant_text !== '') : ?>
                <p style="<?php echo esc_attr($p_style); ?>"><?php echo wp_kses_post($registrant_text); ?></p>
            <?php endif; ?>

            <?php if ($notice_title !== '' || $notice_body !== '') : ?>
                <div style="margin:5px 30px;padding:15px 20px;background:#fff3cd;
                    border-left:4px solid #ffc107;border-radius:6px;">
                    <?php if ($notice_title !== '') : ?>
                        <p style="margin:0 0 10px 0;color:#856404;font-weight:600;font-size:15px;
                            line-height:1.6;font-family:Arial,Helvetica,sans-serif;">
                            <?php echo esc_html($notice_title); ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($notice_body !== '') : ?>
                        <p style="margin:0;color:#856404;font-size:15px;line-height:1.7;
                            font-family:Arial,Helvetica,sans-serif;">
                            <?php echo wp_kses_post($notice_body); ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($outro !== '') : ?>
                <p style="<?php echo esc_attr($p_style); ?>"><?php echo wp_kses_post($outro); ?></p>
            <?php endif; ?>
        </div>
        <div class="footer" style="width:100%;background:<?php echo esc_attr($primary); ?>;color:#fff;
            padding:25px 30px;text-align:center;border-radius:0 0 12px 12px;box-sizing:border-box;">
            <?php
            $org = function_exists('Alttag\Registrations\get_event_organization_team_name')
                ? \Alttag\Registrations\get_event_organization_team_name() : '';
        if ($org) {
            echo '<p style="display:block;width:100%;color:#fff;margin:0;font-size:14px;'
                . 'padding:0;font-family:Arial,Helvetica,sans-serif;line-height:1.6;">'
                . '<b style="color:' . esc_attr($accent) . ';font-weight:700;font-size:17px;">'
                . esc_html($org) . '</b></p>';
        }
        ?>
        </div>
        <?php
        $content = ob_get_clean();

        if (class_exists('\Alttag\Registrations\EmailWrapper')) {
            $content = \Alttag\Registrations\EmailWrapper::wrap($content, $subject);
        }

        return ['subject' => $subject, 'content' => $content];
    }

    public function getGuestRegistrationUrl(): string
    {
        $registration_url = (string) Settings::getValue('hotel_selection.guest_notification_registration_url');
        if ($registration_url === '') {
            return home_url('/');
        }

        if (strpos($registration_url, 'http') !== 0) {
            return home_url($registration_url);
        }

        return $registration_url;
    }

    // =========================================================================
    // Admin participant list columns + export
    // =========================================================================

    public function addAdminColumns($columns)
    {
        $new = [];
        foreach ($columns as $key => $label) {
            if ($key === 'actions') {
                $new['hotel_room'] = __('Hotel', 'alttag-registrations');
                $new['hotel_person_count'] = Settings::getValue('hotel_selection.persons_label');
            }
            $new[$key] = $label;
        }
        if (!isset($columns['actions'])) {
            $new['hotel_room'] = __('Hotel', 'alttag-registrations');
            $new['hotel_person_count'] = Settings::getValue('hotel_selection.persons_label');
        }
        return $new;
    }

    public function renderAdminColumn($content, $column, $post_id)
    {
        if ($column === 'hotel_room') {
            // Multi-night payload renders one line per night; falls back to
            // the legacy single hotel_product_name for older participants.
            $nights_meta = get_post_meta($post_id, 'hotel_selections', true);
            if (is_array($nights_meta) && !empty($nights_meta)) {
                $lines = [];
                foreach ($nights_meta as $night_slug => $sel) {
                    if (!is_array($sel) || empty($sel['product_id'])) {
                        continue;
                    }
                    $name = (string) ($sel['product_name'] ?? '');
                    if ($name === '') {
                        $p = wc_get_product((int) $sel['product_id']);
                        if ($p) {
                            $name = $p->get_name();
                        }
                    }
                    if ($name !== '') {
                        $lines[] = esc_html($name);
                    }
                }
                if (!empty($lines)) {
                    return $this->wrapAdminColumnLines($lines);
                }
            }

            $product_name = trim((string) get_post_meta($post_id, 'hotel_product_name', true));
            if ($product_name !== '') {
                return esc_html($product_name);
            }
            $hotel_id = (int) get_post_meta($post_id, 'hotel_product_id', true);
            if (!$hotel_id) {
                return '—';
            }
            $product = wc_get_product($hotel_id);
            return $product ? esc_html($product->get_name()) : '—';
        }

        if ($column === 'hotel_person_count') {
            // Multi-night display — one line per booked room, parallel to
            // the Hotel column so admin can scan both columns line-by-line.
            $nights_meta = get_post_meta($post_id, 'hotel_selections', true);
            if (is_array($nights_meta) && !empty($nights_meta)) {
                $lines = [];
                foreach ($nights_meta as $sel) {
                    if (!is_array($sel)) {
                        continue;
                    }
                    $persons = (int) ($sel['person_count'] ?? 0);
                    if ($persons <= 0) {
                        continue;
                    }
                    $line = esc_html($this->formatPersonsLabel($persons));

                    $guests = $this->synthesizeGuestsArray($sel, $persons);
                    $guest_pieces = [];
                    for ($idx = 1; $idx <= $persons; $idx++) {
                        $g = $guests[$idx] ?? null;
                        if (!is_array($g)) {
                            continue;
                        }
                        $name = trim((string) ($g['name'] ?? ''));
                        $email = trim((string) ($g['email'] ?? ''));
                        if ($name === '' && $email === '') {
                            continue;
                        }
                        if ($name !== '' && $email !== '') {
                            $guest_pieces[] = esc_html($name) . ' (' . esc_html($email) . ')';
                        } else {
                            $guest_pieces[] = esc_html($name !== '' ? $name : $email);
                        }
                    }
                    if (!empty($guest_pieces)) {
                        $line .= ' <small>(' . implode(', ', $guest_pieces) . ')</small>';
                    }
                    $lines[] = $line;
                }
                if (!empty($lines)) {
                    return $this->wrapAdminColumnLines($lines);
                }
            }

            // Legacy fallback — flat post meta (single-night participants
            // from before the multi-night refactor).
            $persons = (int) get_post_meta($post_id, 'hotel_person_count', true);
            if ($persons <= 0) {
                return '—';
            }
            $display = esc_html($this->formatPersonsLabel($persons));

            if ($persons >= 2) {
                $guest = trim((string) get_post_meta($post_id, 'hotel_guest_name', true));
                $guest_email = trim((string) get_post_meta($post_id, 'hotel_guest_email', true));
                $guest_details = array_filter([$guest, $guest_email], 'strlen');
                if (!empty($guest_details)) {
                    $display .= ' <small>(' . esc_html(implode(', ', $guest_details)) . ')</small>';
                }

                $extra_guests = get_post_meta($post_id, 'hotel_extra_guests', true);
                if (is_array($extra_guests)) {
                    foreach ($extra_guests as $person_index => $guest) {
                        if ((int) $person_index > $persons) {
                            continue;
                        }
                        $name = trim((string) ($guest['name'] ?? ''));
                        $email = trim((string) ($guest['email'] ?? ''));
                        $parts = array_filter([$name, $email], 'strlen');
                        if (!empty($parts)) {
                            $display .= '<br><small>' . (int) $person_index . '. '
                                . esc_html(implode(', ', $parts)) . '</small>';
                        }
                    }
                }
            }

            return $display;
        }

        return $content;
    }

    /**
     * Wrap one-line-per-night admin column entries so the visual mapping
     * between the Hotel and Počet osôb columns is unambiguous. Each line
     * gets a leading "N." number and a dashed bottom border (except the
     * last). Single-row participants render without the prefix to keep the
     * legacy compact look.
     *
     * @param string[] $lines Already-escaped HTML lines.
     */
    private function wrapAdminColumnLines(array $lines): string
    {
        $lines = array_values($lines);
        $count = count($lines);
        if ($count === 0) {
            return '—';
        }
        if ($count === 1) {
            return $lines[0];
        }
        $html = '';
        foreach ($lines as $i => $line) {
            $is_last = ($i === $count - 1);
            $style = 'padding:4px 0;'
                . ($is_last ? '' : 'margin-bottom:4px;border-bottom:1px dashed rgba(0,0,0,0.18);');
            $html .= '<div style="' . esc_attr($style) . '">'
                . '<span style="opacity:0.55; margin-right:4px;">' . ($i + 1) . '.</span> '
                . $line
                . '</div>';
        }
        return $html;
    }

    /**
     * Render the "Hotels" multi-select in the participant admin list,
     * populated from products actually booked across all participants
     * (legacy single meta + new repeated meta). Plugged into the generic
     * `alttag_registrations_participant_after_filters` action so core
     * stays hotel-agnostic.
     */
    public function renderHotelAdminFilter()
    {
        global $typenow;
        if ($typenow !== 'participant') {
            return;
        }
        $options = $this->getHotelAdminFilterOptions();
        if (empty($options)) {
            return;
        }
        $current_raw = $_GET['participant_hotel'] ?? [];
        $current = array_map('intval', (array) $current_raw);
        $current = array_filter($current, static function ($v) { return $v > 0; });

        // size attr controls how many rows the multi-select shows at once —
        // clamped between 3 and the option count so the UI never balloons.
        $size = max(3, min(count($options) + 1, 6));

        echo '<select name="participant_hotel[]" multiple="multiple" '
            . 'size="' . (int) $size . '" '
            . 'title="' . esc_attr__('Hold Ctrl/Cmd to select multiple hotels', 'alttag-registrations') . '" '
            . 'class="kmkb-hotel-filter" '
            . 'style="vertical-align:top; min-width:240px;">';
        foreach ($options as $pid => $label) {
            printf(
                '<option value="%d" %s>%s</option>',
                (int) $pid,
                in_array((int) $pid, $current, true) ? 'selected' : '',
                esc_html($label)
            );
        }
        echo '</select>';
    }

    /**
     * Lookup map [product_id => title] of every hotel product that has at
     * least one participant attached, considering both the legacy single
     * meta key and the new per-night repeated meta. Cached per-request.
     *
     * @return array<int, string>
     */
    private function getHotelAdminFilterOptions(): array
    {
        global $wpdb;
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $ids = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key IN ('hotel_booked_product_id', 'hotel_product_id')
              AND pm.meta_value REGEXP '^[0-9]+$'
              AND pm.meta_value != '0'
              AND p.post_type = 'participant'
              AND p.post_status NOT IN ('trash', 'auto-draft')"
        );
        $options = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (!$id) {
                continue;
            }
            $title = get_the_title($id);
            $options[$id] = $title !== '' ? $title : sprintf('#%d', $id);
        }
        natcasesort($options);
        $cache = $options;
        return $cache;
    }

    /**
     * Add a meta_query clause that matches any of the chosen hotels against
     * both the new repeated 'hotel_booked_product_id' rows AND the legacy
     * single 'hotel_product_id' key. Accepts a multi-select array so admin
     * can list participants across several hotels at once.
     */
    public function applyHotelAdminFilterQuery($meta_query)
    {
        if (empty($_GET['participant_hotel'])) {
            return $meta_query;
        }
        $hotel_ids = array_map('intval', (array) $_GET['participant_hotel']);
        $hotel_ids = array_values(array_filter(
            $hotel_ids,
            static function ($id) { return $id > 0; }
        ));
        if (empty($hotel_ids)) {
            return $meta_query;
        }
        $meta_query[] = [
            'relation' => 'OR',
            ['key' => 'hotel_booked_product_id', 'value' => $hotel_ids, 'compare' => 'IN'],
            ['key' => 'hotel_product_id', 'value' => $hotel_ids, 'compare' => 'IN'],
        ];
        return $meta_query;
    }

    /**
     * Expand a single participant row into one row per booked night so the
     * Excel export keeps multi-night customers from being collapsed to a
     * single line. Each output row inherits the participant's non-hotel
     * fields and overlays the night-specific hotel_* values.
     *
     * @param array $rows           One-or-more participant row arrays.
     * @param int   $participant_id Participant post ID.
     * @return array<int, array>
     */
    public function expandExportRowsPerNight($rows, $participant_id)
    {
        $nights_meta = get_post_meta((int) $participant_id, 'hotel_selections', true);
        if (!is_array($nights_meta) || count($nights_meta) < 2) {
            return $rows;
        }
        $effective_max = $this->getEffectiveMaxPersons();

        $expanded = [];
        foreach ($rows as $base) {
            if (!is_array($base)) {
                $expanded[] = $base;
                continue;
            }
            foreach ($nights_meta as $sel) {
                if (!is_array($sel) || empty($sel['product_id'])) {
                    continue;
                }
                $product_name = (string) ($sel['product_name'] ?? '');
                if ($product_name === '') {
                    $p = wc_get_product((int) $sel['product_id']);
                    if ($p) {
                        $product_name = $p->get_name();
                    }
                }
                $row = $base;
                $row['hotel_product_id'] = (int) $sel['product_id'];
                $row['hotel_product_name'] = $product_name;
                $row['hotel_person_count'] = (string) ($sel['person_count'] ?? '');
                $row['hotel_guest_name'] = (string) ($sel['guest_name'] ?? '');
                $row['hotel_guest_email'] = (string) ($sel['guest_email'] ?? '');
                $row['hotel_extra_guests'] = is_array($sel['extra_guests'] ?? null)
                    ? $sel['extra_guests'] : [];

                // Per-guest compact rows that mirror what saveSelectionToParticipant
                // writes for the first night — wiped and re-filled per night so
                // each exported row matches its own night's people.
                $row['hotel_guest_2'] = $this->formatGuestRowValue(
                    (string) ($sel['guest_name'] ?? ''),
                    (string) ($sel['guest_email'] ?? '')
                );
                for ($i = 3; $i <= $effective_max; $i++) {
                    $g = $sel['extra_guests'][$i] ?? null;
                    $row["hotel_guest_{$i}"] = is_array($g)
                        ? $this->formatGuestRowValue(
                            (string) ($g['name'] ?? ''),
                            (string) ($g['email'] ?? '')
                        )
                        : '';
                }
                $expanded[] = $row;
            }
        }
        return !empty($expanded) ? $expanded : $rows;
    }

    public function addExportFields($export_fields, $metaFields)
    {
        if (!isset($export_fields['hotel_product_name'])) {
            $export_fields['hotel_product_name'] = __('Hotel', 'alttag-registrations');
        }
        if (!isset($export_fields['hotel_product_id'])) {
            $export_fields['hotel_product_id'] = __('Hotel product ID', 'alttag-registrations');
        }
        if (!isset($export_fields['hotel_person_count'])) {
            $export_fields['hotel_person_count'] = Settings::getValue('hotel_selection.persons_label');
        }
        // Legacy split fields `hotel_guest_name` / `hotel_guest_email` are
        // intentionally dropped — they duplicate the compact `hotel_guest_2`
        // column ("Name (email)") which is uniform with hotel_guest_3/4 for
        // higher-occupancy rooms. Still registered as meta for admin display
        // and post-meta consumers; just absent from the spreadsheet.
        unset($export_fields['hotel_guest_name'], $export_fields['hotel_guest_email']);
        return $export_fields;
    }

    /**
     * Register hotel meta fields so they have human-readable labels in
     * admin verification/meta-box rendering. The product-name snapshot is
     * the user-facing field; the raw ID stays available for export and
     * machine consumers.
     */
    public function registerMetaFields($fields)
    {
        // Composite HTML summary covering every booked night — rendered in
        // the participant detail meta box (admin) and on the verification
        // page. Value is computed on the fly from hotel_selections post meta.
        $fields['hotel_summary'] = [
            'label' => __('Accommodation', 'alttag-registrations'),
            'admin_label' => __('Accommodation', 'alttag-registrations'),
            'type' => 'html',
            'readonly' => true,
            'value_callback' => [$this, 'buildParticipantHotelSummaryHtml'],
        ];
        $fields['hotel_product_name'] = [
            'label' => __('Hotel', 'alttag-registrations'),
            'type' => 'text',
            'readonly' => true,
        ];
        $fields['hotel_product_id'] = [
            'label' => __('Hotel product ID', 'alttag-registrations'),
            'type' => 'text',
            'readonly' => true,
        ];
        $fields['hotel_person_count'] = [
            'label' => Settings::getValue('hotel_selection.persons_label'),
            'type' => 'text',
            'readonly' => true,
        ];
        $fields['hotel_guest_name'] = [
            'label' => Settings::getValue('hotel_selection.guest_name_label'),
            'type' => 'text',
            'readonly' => true,
        ];
        $fields['hotel_guest_email'] = [
            'label' => Settings::getValue('hotel_selection.guest_email_label'),
            'type' => 'text',
            'readonly' => true,
        ];
        // Compact "Hosť X: Name (email)" rows for guests 2..max — populated
        // by saveSelectionToParticipant. Guest 2 derives from the legacy
        // hotel_guest_name + hotel_guest_email pair; guests 3+ from the
        // hotel_extra_guests array.
        for ($i = 2; $i <= $this->getEffectiveMaxPersons(); $i++) {
            $fields["hotel_guest_{$i}"] = [
                'label' => $this->guestSummaryLabel($i),
                'type' => 'text',
                'readonly' => true,
            ];
        }
        return $fields;
    }

    /**
     * Add hotel section to the participant edit meta box.
     * Single composite field renders every booked night as HTML so the admin
     * sees the full multi-night picture in one block — same pattern the
     * verification page uses. The flat hotel_* fields are still registered
     * (so the listing column / export keep working) but no longer surfaced
     * here.
     */
    public function addMetaBoxSection($sections)
    {
        if (!is_array($sections)) {
            $sections = [];
        }

        $sections['hotel'] = [
            'title' => __('Hotel selection', 'alttag-registrations'),
            'fields' => ['hotel_summary'],
        ];

        return $sections;
    }

    // =========================================================================
    // Public verification page (/verify/{vs}/)
    // =========================================================================

    /**
     * Add hotel fields to the personal section so the verification template
     * renders them next to the participant's other meta values.
     */
    public function addVerificationFields($sections)
    {
        if (!is_array($sections)) {
            $sections = [];
        }

        // One composite field renders the full multi-night summary as HTML.
        // Dedicated section keeps accommodation out of "Personal information"
        // and the template skips empty fields automatically.
        $sections['hotel'] = [
            'title' => __('Accommodation', 'alttag-registrations'),
            'fields' => ['hotel_summary'],
        ];

        return $sections;
    }

    /**
     * Populate $data with hotel meta read from the participant post.
     */
    public function populateVerificationData($data, $participant_id, $participant)
    {
        $data['hotel_product_id'] = (string) get_post_meta($participant_id, 'hotel_product_id', true);
        $data['hotel_product_name'] = (string) get_post_meta($participant_id, 'hotel_product_name', true);
        $data['hotel_person_count'] = (string) get_post_meta($participant_id, 'hotel_person_count', true);
        $data['hotel_guest_name'] = (string) get_post_meta($participant_id, 'hotel_guest_name', true);
        $data['hotel_guest_email'] = (string) get_post_meta($participant_id, 'hotel_guest_email', true);

        if ($data['hotel_product_name'] === '' && $data['hotel_product_id'] !== '') {
            $product = wc_get_product((int) $data['hotel_product_id']);
            if ($product) {
                $data['hotel_product_name'] = $product->get_name();
            }
        }

        if ((int) $data['hotel_person_count'] >= 2) {
            $data['hotel_guest_2'] = $this->formatGuestRowValue(
                $data['hotel_guest_name'],
                $data['hotel_guest_email']
            );
        }
        $extras = get_post_meta($participant_id, 'hotel_extra_guests', true);
        if (is_array($extras)) {
            foreach ($extras as $idx => $guest) {
                $idx = (int) $idx;
                $data["hotel_guest_{$idx}"] = $this->formatGuestRowValue(
                    (string) ($guest['name'] ?? ''),
                    (string) ($guest['email'] ?? '')
                );
            }
        }

        // Composite multi-night HTML summary — only key the verification
        // section now reads. Empty when the participant has no accommodation.
        $data['hotel_summary'] = $this->buildParticipantHotelSummaryHtml($participant_id);

        return $data;
    }

    /**
     * Build a compact HTML block summarising every night of accommodation a
     * participant booked. Used by the verification page and admin meta box.
     * Returns an empty string when no accommodation data exists.
     */
    public function buildParticipantHotelSummaryHtml(int $participant_id): string
    {
        $nights_meta = get_post_meta($participant_id, 'hotel_selections', true);
        if (!is_array($nights_meta) || empty($nights_meta)) {
            // Fall back to the legacy single-selection meta so older orders
            // keep rendering after the refactor.
            $legacy_product_id = (int) get_post_meta($participant_id, 'hotel_product_id', true);
            if ($legacy_product_id) {
                $nights_meta = ['legacy' => [
                    'product_id' => $legacy_product_id,
                    'product_name' => (string) get_post_meta($participant_id, 'hotel_product_name', true),
                    'person_count' => (string) get_post_meta($participant_id, 'hotel_person_count', true),
                    'guest_name' => (string) get_post_meta($participant_id, 'hotel_guest_name', true),
                    'guest_email' => (string) get_post_meta($participant_id, 'hotel_guest_email', true),
                    'extra_guests' => is_array(get_post_meta($participant_id, 'hotel_extra_guests', true))
                        ? get_post_meta($participant_id, 'hotel_extra_guests', true)
                        : [],
                ]];
            }
        }
        if (empty($nights_meta)) {
            return '';
        }

        $multi = count($nights_meta) > 1;
        $night_labels = [];
        foreach ($this->getAvailableNights() as $slug => $night_data) {
            $night_labels[$slug] = $night_data['label'] ?? '';
        }
        $persons_label = Settings::getValue('hotel_selection.persons_label');

        $html = '';
        foreach ($nights_meta as $night_slug => $sel) {
            if (!is_array($sel) || empty($sel['product_id'])) {
                continue;
            }
            $hotel_name = (string) ($sel['product_name'] ?? '');
            if ($hotel_name === '') {
                $p = wc_get_product((int) $sel['product_id']);
                if ($p) {
                    $hotel_name = $p->get_name();
                }
            }
            if ($hotel_name === '') {
                continue;
            }

            // Prefer the slug carried in the row data — falls back to the
            // foreach key for legacy keyed-by-slug payloads.
            $resolved_slug = (string) ($sel['night'] ?? $night_slug);
            $html .= '<div style="margin-top:8px;padding-top:8px;border-top:1px dashed rgba(0,0,0,0.1);">';
            if ($multi && !empty($night_labels[$resolved_slug])) {
                $html .= '<div style="font-weight:600;opacity:0.8;text-transform:uppercase;'
                    . 'letter-spacing:0.04em;font-size:12px;margin-bottom:4px;">'
                    . esc_html($night_labels[$resolved_slug]) . '</div>';
            }
            $html .= '<div style="font-weight:600;">' . esc_html($hotel_name) . '</div>';
            $persons = (int) ($sel['person_count'] ?? 0);
            if ($persons > 0) {
                $html .= '<div>'
                    . esc_html($persons_label) . ': '
                    . esc_html($this->formatPersonsLabel($persons))
                    . '</div>';
            }
            // Iterate the synthesized 1..N guests array so guest #1 also
            // appears in the admin/verify summary. Legacy single-night
            // selections without guest #1 just show what they have.
            $guests = $this->synthesizeGuestsArray((array) $sel, $persons);
            for ($idx = 1; $idx <= $persons; $idx++) {
                $g = $guests[$idx] ?? null;
                if (!is_array($g)) {
                    continue;
                }
                $val = $this->formatGuestRowValue(
                    (string) ($g['name'] ?? ''),
                    (string) ($g['email'] ?? '')
                );
                if ($val !== '') {
                    $html .= '<div>' . esc_html($this->guestSummaryLabel($idx)) . ': '
                        . esc_html($val) . '</div>';
                }
            }
            $html .= '</div>';
        }
        return $html;
    }

    /**
     * Resolve the displayed value for the verification page.
     * - hotel_product_name → snapshot name (preferred), with ID fallback
     * - hotel_product_id → product title (legacy)
     * - hotel_person_count → "1 osoba" / "2 osoby"
     * - others → raw value
     */
    public function formatVerificationValue($value, $field_id, $data)
    {
        // Composite multi-night HTML — passed through verbatim. The
        // verification template echoes field values unescaped, and the HTML
        // is already built from sanitised post meta in
        // buildParticipantHotelSummaryHtml().
        if ($field_id === 'hotel_summary') {
            return wp_kses(
                (string) $value,
                [
                    'div' => ['style' => []],
                    'br' => [],
                    'strong' => [],
                ]
            );
        }

        if ($field_id === 'hotel_product_name') {
            if ($value !== '' && $value !== null) {
                return (string) $value;
            }
            $fallback_id = (int) ($data['hotel_product_id'] ?? 0);
            if (!$fallback_id) {
                return '';
            }
            $product = wc_get_product($fallback_id);
            return $product ? $product->get_name() : '';
        }

        if ($field_id === 'hotel_product_id') {
            $product_id = (int) $value;
            if (!$product_id) {
                return '';
            }
            $product = wc_get_product($product_id);
            return $product ? $product->get_name() : '';
        }

        if ($field_id === 'hotel_person_count') {
            $persons = (int) $value;
            return $persons > 0 ? $this->formatPersonsLabel($persons) : '';
        }

        return $value;
    }

    /**
     * Localised labels for the verification rows.
     */
    public function getVerificationLabel($label, $field_id, $data)
    {
        if ($field_id === 'hotel_summary') {
            return __('Accommodation', 'alttag-registrations');
        }
        if (preg_match('/^hotel_guest_(\d+)$/', $field_id, $m)) {
            return $this->guestSummaryLabel((int) $m[1]);
        }
        switch ($field_id) {
            case 'hotel_product_name':
            case 'hotel_product_id':
                return __('Hotel', 'alttag-registrations');
            case 'hotel_person_count':
                return Settings::getValue('hotel_selection.persons_label');
            case 'hotel_guest_name':
                return Settings::getValue('hotel_selection.guest_name_label');
            case 'hotel_guest_email':
                return Settings::getValue('hotel_selection.guest_email_label');
        }

        return $label;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Read the saved hotel selection for an order (set by saveSelectionToOrder).
     * Returns ['hotel_name', 'persons', 'guest_name', 'guest_email', 'product_id']
     * or empty array when no hotel was selected.
     */
    private function getOrderHotelInfo($order): array
    {
        $list = $this->getOrderHotelInfos($order);
        return $list ? reset($list) : [];
    }

    /**
     * List of hotel info entries for an order — one per night. Each entry has
     * the same shape as the legacy single getOrderHotelInfo() return value
     * plus an extra `night` slug key. Empty array when the order has no
     * accommodation, so callers can iterate without null checks.
     *
     * @return array<int, array{product_id:int, hotel_name:string, persons:int, guest_name:string, guest_email:string, extra_guests:array, night:string, night_label:string}>
     */
    private function getOrderHotelInfos($order): array
    {
        if (!$order instanceof \WC_Order) {
            return [];
        }

        // Prefer the multi-night payload; fall back to the legacy single
        // selection so existing orders keep rendering after the refactor.
        $nights_meta = $order->get_meta(self::ORDER_META_NIGHTS_KEY);
        if (!is_array($nights_meta) || empty($nights_meta)) {
            $legacy = $order->get_meta(self::ORDER_META_KEY);
            if (is_array($legacy) && !empty($legacy['product_id'])) {
                $nights_meta = ['legacy' => $legacy];
            }
        }
        if (empty($nights_meta)) {
            return [];
        }

        $night_labels = [];
        foreach ($this->getAvailableNights() as $slug => $night_data) {
            $night_labels[$slug] = $night_data['label'] ?? '';
        }

        $infos = [];
        foreach ($nights_meta as $night_slug => $selection) {
            if (!is_array($selection) || empty($selection['product_id'])) {
                continue;
            }
            $product_id = (int) $selection['product_id'];
            $hotel_name = trim((string) ($selection['product_name'] ?? ''));
            if ($hotel_name === '' && $product_id) {
                $product = wc_get_product($product_id);
                if ($product) {
                    $hotel_name = $product->get_name();
                }
            }
            if ($hotel_name === '') {
                continue;
            }
            $resolved_slug = (string) ($selection['night'] ?? $night_slug);
            $persons = (int) ($selection['person_count'] ?? 0);
            // Synthesize a uniform 1..N guests array even for legacy orders
            // so display code has a single path. New orders carry the
            // canonical 'guests' array; old orders only have guest_first_name
            // (= guest 2) + extra_guests (= guests 3+) and no guest #1 info.
            $guests = $this->synthesizeGuestsArray($selection, $persons);
            $infos[] = [
                'night' => $resolved_slug,
                // Prefer the slug from the data itself so numerically-indexed
                // lists still pick up the right label.
                'night_label' => $night_labels[$resolved_slug] ?? '',
                'product_id' => $product_id,
                'hotel_name' => $hotel_name,
                'persons' => $persons,
                'guest_name' => (string) ($selection['guest_name'] ?? ''),
                'guest_email' => (string) ($selection['guest_email'] ?? ''),
                'extra_guests' => is_array($selection['extra_guests'] ?? null) ? $selection['extra_guests'] : [],
                'guests' => $guests,
            ];
        }
        return $infos;
    }

    private function formatPersonsLabel(int $persons): string
    {
        $persons = max(1, $persons);
        return sprintf(
            _n('%d person', '%d persons', $persons, 'alttag-registrations'),
            $persons
        );
    }

    /**
     * Build a compact price label for a room dropdown option.
     *   Single fixed price                → "139,65 €"
     *   Different prices across the range → "od 139,65 €" (cheapest first)
     *
     * Goes through getProductPriceForPersons() so any pricing filter
     * (matrix, 1/2-fixed, surcharge, flat) is respected.
     */
    private function buildRoomPriceLabel(\WC_Product $product, int $min, int $max): string
    {
        $price_min = $this->getProductPriceForPersons($product, max(1, $min));
        $price_max = $this->getProductPriceForPersons($product, max($min, $max));

        $format_amount = static function (float $amount): string {
            // wc_price returns HTML — strip tags + decode entities so the
            // label is plain text suitable for a <select> option.
            return trim(html_entity_decode(
                wp_strip_all_tags(wc_price($amount)),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ));
        };

        if ($min === $max || abs($price_min - $price_max) < 0.005) {
            return $format_amount($price_min);
        }
        return sprintf(
            /* translators: %s is the lowest price for a room (e.g. "139,65 €") */
            __('from %s', 'alttag-registrations'),
            $format_amount($price_min)
        );
    }

    /**
     * Produce a uniform 1..N guests array for display purposes. New rows
     * already carry `guests`; legacy rows have guest_first_name (= guest 2)
     * + extra_guests (= guests 3+), no info for guest 1.
     *
     * @return array<int, array{first_name:string, last_name:string, name:string, email:string}>
     */
    private function synthesizeGuestsArray(array $selection, int $persons): array
    {
        if (isset($selection['guests']) && is_array($selection['guests']) && !empty($selection['guests'])) {
            $out = [];
            foreach ($selection['guests'] as $idx => $g) {
                $idx = (int) $idx;
                if ($idx < 1) {
                    continue;
                }
                $first = (string) ($g['first_name'] ?? '');
                $last = (string) ($g['last_name'] ?? '');
                $out[$idx] = [
                    'first_name' => $first,
                    'last_name' => $last,
                    'name' => (string) ($g['name'] ?? trim($first . ' ' . $last)),
                    'email' => (string) ($g['email'] ?? ''),
                ];
            }
            return $out;
        }

        // Legacy synthesis — guest 1 stays empty (the buyer was implied);
        // guest 2 lives in flat slots; guests 3+ live in extra_guests[].
        $out = [];
        if ($persons >= 2) {
            $g2_first = (string) ($selection['guest_first_name'] ?? '');
            $g2_last = (string) ($selection['guest_last_name'] ?? '');
            $g2_name = (string) ($selection['guest_name'] ?? trim($g2_first . ' ' . $g2_last));
            $out[2] = [
                'first_name' => $g2_first,
                'last_name' => $g2_last,
                'name' => $g2_name,
                'email' => (string) ($selection['guest_email'] ?? ''),
            ];
        }
        $extras = is_array($selection['extra_guests'] ?? null) ? $selection['extra_guests'] : [];
        foreach ($extras as $idx => $g) {
            $idx = (int) $idx;
            if ($idx < 3 || !is_array($g)) {
                continue;
            }
            $first = (string) ($g['first_name'] ?? '');
            $last = (string) ($g['last_name'] ?? '');
            $out[$idx] = [
                'first_name' => $first,
                'last_name' => $last,
                'name' => (string) ($g['name'] ?? trim($first . ' ' . $last)),
                'email' => (string) ($g['email'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Combine fresh POST first/last name with the source array's stored guest_name.
     * Used during checkout submit so that values typed but not yet AJAX-pushed are
     * persisted instead of stale session/cart_item values.
     */
    private function resolveGuestName(array $source): string
    {
        $first = isset($_POST['hotel_guest_first_name'])
            ? sanitize_text_field(wp_unslash((string) $_POST['hotel_guest_first_name'])) : '';
        $last = isset($_POST['hotel_guest_last_name'])
            ? sanitize_text_field(wp_unslash((string) $_POST['hotel_guest_last_name'])) : '';
        $combined = trim($first . ' ' . $last);
        if ($combined !== '') {
            return $combined;
        }

        return (string) ($source['guest_name'] ?? $source['alttag_hotel_guest_name'] ?? '');
    }

    private function resolveGuestEmail(array $source): string
    {
        if (isset($_POST['hotel_guest_email'])) {
            $email = sanitize_email(wp_unslash((string) $_POST['hotel_guest_email']));
            if ($email !== '') {
                return $email;
            }
        }

        return (string) ($source['guest_email'] ?? $source['alttag_hotel_guest_email'] ?? '');
    }

    /**
     * Resolve the guests for persons 3..N — same precedence as resolveGuestName:
     * fresh POST values win over the source array (session or cart_item).
     * Returns ['<person_index>' => ['first_name', 'last_name', 'name', 'email'], …]
     * for non-empty entries only.
     */
    private function resolveExtraGuests(array $source): array
    {
        $stored = $source['extra_guests']
            ?? $source['alttag_hotel_extra_guests']
            ?? [];
        $stored = is_array($stored) ? $stored : [];

        $guests = [];
        for ($i = 3; $i <= $this->getEffectiveMaxPersons(); $i++) {
            $first = isset($_POST["hotel_guest_{$i}_first_name"])
                ? sanitize_text_field(wp_unslash((string) $_POST["hotel_guest_{$i}_first_name"]))
                : (string) ($stored[$i]['first_name'] ?? '');
            $last = isset($_POST["hotel_guest_{$i}_last_name"])
                ? sanitize_text_field(wp_unslash((string) $_POST["hotel_guest_{$i}_last_name"]))
                : (string) ($stored[$i]['last_name'] ?? '');
            $email = isset($_POST["hotel_guest_{$i}_email"])
                ? sanitize_email(wp_unslash((string) $_POST["hotel_guest_{$i}_email"]))
                : (string) ($stored[$i]['email'] ?? '');

            $name = trim($first . ' ' . $last);
            if ($first === '' && $last === '' && $email === '') {
                continue;
            }
            $guests[$i] = [
                'first_name' => $first,
                'last_name' => $last,
                'name' => $name,
                'email' => $email,
            ];
        }
        return $guests;
    }

    // =========================================================================
    // Frontend JS
    // =========================================================================

    public function renderCheckoutJs()
    {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        if (!$this->isActiveForCart()) {
            return;
        }

        if (empty($this->getAvailableNights())) {
            return;
        }

        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('alttag_hotel_cart');
        ?>
        <style type="text/css">
            .hotel-field--hidden { display: none !important; }

            /* Ghost-style repeater: no white card. Rows are subtle
               translucent surfaces that blend with whatever page bg
               surrounds them, so the section feels lighter and matches the
               rest of the checkout instead of dominating it. */
            #hotel_rooms_field .woocommerce-input-wrapper { width: 100%; }
            .hotel-repeater {
                display: flex;
                flex-direction: column;
                margin-top: 6px;
                padding: 14px;
                color: inherit;
                background: rgba(255, 255, 255, 0.03);
                border: 1px solid rgba(255, 255, 255, 0.08);
                border-radius: 10px;
            }
            .hotel-repeater-rows { display: block; }
            .hotel-repeater-row {
                display: block;
                position: relative;
            }
            .hotel-repeater-row + .hotel-repeater-row {
                margin-top: 14px;
            }
            .hotel-repeater-row-header {
                display: grid;
                grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr) minmax(0, 0.9fr);
                gap: 12px;
                align-items: start;
                /* Leave room for the absolute-positioned × button so labels
                   never run under it. */
                padding-right: 40px;
            }
            @media (max-width: 640px) {
                /* Phones get a single stacked column — each field on its
                   own row for full-width legibility. The × stays absolute
                   top-right (set above) and overlaps the first cell's
                   label area thanks to the row-header padding-right. */
                .hotel-repeater-row-header {
                    grid-template-columns: 1fr;
                }
            }
            .hotel-repeater-cell { display: block; min-width: 0; }
            .hotel-repeater-cell label {
                display: block;
                font-size: 11px;
                font-weight: 500;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: inherit;
                opacity: 0.55;
                margin: 0 0 5px;
            }
            /* Inputs use a very subtle light surface so they stay legible
               but don't punch big white holes through the dark theme. */
            .hotel-repeater-cell select,
            .hotel-repeater-guest-fields input {
                width: 100%;
                max-width: 100%;
                background: rgba(255, 255, 255, 0.92);
                color: #1a1a1a;
                border: 1px solid rgba(0, 0, 0, 0.08);
                border-radius: 6px;
                padding: 9px 11px;
                font-size: 14px;
                line-height: 1.3;
                box-shadow: none;
                box-sizing: border-box;
            }
            .hotel-repeater-cell select {
                /* Extra right padding so the visible value never crashes
                   into the browser's native dropdown arrow. !important
                   defeats theme styles that hardcode their own padding. */
                padding-right: 20px !important;
                text-overflow: ellipsis;
                white-space: nowrap;
                overflow: hidden;
            }
            .hotel-repeater-guest-fields input::placeholder {
                color: rgba(0, 0, 0, 0.4);
            }
            .hotel-repeater-cell select:focus,
            .hotel-repeater-guest-fields input:focus {
                outline: none;
                border-color: rgba(255, 255, 255, 0.5);
                box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.18);
            }
            .hotel-repeater-remove {
                position: absolute;
                top: 0;
                right: 0;
                width: 28px;
                height: 28px;
                border-radius: 6px;
                border: 1px solid rgba(255, 255, 255, 0.15);
                background: rgba(255, 255, 255, 0.05);
                font-size: 16px;
                font-weight: 400;
                line-height: 1;
                cursor: pointer;
                padding: 0;
                color: inherit;
                opacity: 0.55;
                transition: opacity 120ms, background-color 120ms, border-color 120ms, color 120ms;
            }
            .hotel-repeater-remove:hover {
                background: rgba(255, 99, 99, 0.18);
                border-color: rgba(255, 99, 99, 0.45);
                color: #ff8a8a;
                opacity: 1;
            }
            .hotel-repeater-guests {
                margin-top: 14px;
                padding-top: 12px;
                border-top: 1px dashed rgba(255, 255, 255, 0.12);
            }
            .hotel-repeater-guest {
                display: block;
                padding: 8px 0;
            }
            .hotel-repeater-guest + .hotel-repeater-guest {
                margin-top: 8px;
                border-top: 1px dashed rgba(255, 255, 255, 0.08);
            }
            .hotel-repeater-guest-heading {
                font-size: 12px;
                font-weight: 600;
                color: inherit;
                opacity: 0.7;
                margin-bottom: 6px;
                letter-spacing: 0.02em;
            }
            .hotel-repeater-guest-fields {
                display: grid;
                grid-template-columns: 1fr 1fr 1.4fr;
                gap: 8px;
            }
            @media (max-width: 640px) {
                .hotel-repeater-guest-fields { grid-template-columns: 1fr; }
            }
            .hotel-repeater-add {
                align-self: flex-end;
                margin-top: 16px;
                background: transparent;
                color: inherit;
                border: 1px dashed rgba(255, 255, 255, 0.25);
                border-radius: 6px;
                padding: 8px 14px;
                font-size: 13px;
                font-weight: 500;
                cursor: pointer;
                opacity: 0.8;
                transition: opacity 120ms, background-color 120ms, border-color 120ms;
            }
            .hotel-repeater-add:hover {
                background: rgba(255, 255, 255, 0.06);
                border-color: rgba(255, 255, 255, 0.45);
                opacity: 1;
            }
        </style>
        <script type="text/javascript">
        (function ($) {
            var $container = $('#hotel_rooms_field .hotel-repeater');
            if (!$container.length) { return; }

            var rawConfig = $container.attr('data-config');
            var config;
            try { config = JSON.parse(rawConfig); }
            catch (e) { return; }

            var $rows = $container.find('.hotel-repeater-rows');
            var $template = $container.find('.hotel-repeater-template');
            var $addBtn = $container.find('.hotel-repeater-add');
            var $masterField = $('#hotel_master_interested_field');
            var $master = $('#hotel_master_interested');
            var debounceTimer;
            var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;

            // -- helpers --------------------------------------------------

            function masterChecked() { return $master.is(':checked'); }

            function findNight(slug) {
                if (!slug) { return null; }
                for (var i = 0; i < config.nights.length; i++) {
                    if (config.nights[i].slug === slug) { return config.nights[i]; }
                }
                return null;
            }

            function findProduct(night, productId) {
                if (!night) { return null; }
                var id = parseInt(productId, 10);
                for (var i = 0; i < night.products.length; i++) {
                    if (parseInt(night.products[i].id, 10) === id) { return night.products[i]; }
                }
                return null;
            }

            // Strip every option from a <select> then append fresh ones from
            // the supplied iterable. selectedValue is kept selected if found.
            // Pass placeholder=null to skip the empty "— placeholder —" entry
            // (used for the persons dropdown, which is just hidden when no
            // product is picked so the "—" option never needs to show).
            function fillOptions($select, placeholder, items, selectedValue) {
                var html = '';
                if (placeholder !== null && placeholder !== undefined && placeholder !== '') {
                    html += '<option value="">' + escapeHtml(placeholder) + '</option>';
                }
                var has = false;
                items.forEach(function (item) {
                    var sel = (String(item.value) === String(selectedValue || '')) ? ' selected' : '';
                    if (sel) { has = true; }
                    html += '<option value="' + escapeAttr(item.value) + '"' + sel + '>'
                        + escapeHtml(item.label) + '</option>';
                });
                $select.html(html);
                if (!has) { $select.val(''); }
            }

            function escapeHtml(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }
            function escapeAttr(s) {
                return escapeHtml(s).replace(/'/g, '&#39;');
            }

            // -- per-row syncing ------------------------------------------

            // Rebuild the room dropdown's options based on the row's chosen
            // night, then rebuild persons based on the chosen room. Always
            // refresh the guest visibility from the current persons count.
            function syncRow($row) {
                var $night = $row.find('.hotel-repeater-night');
                var $product = $row.find('.hotel-repeater-product');
                var $persons = $row.find('.hotel-repeater-persons');
                var $personsCell = $row.find('.hotel-repeater-cell-persons');

                var nightSlug = $night.val();
                var night = findNight(nightSlug);

                // Room options keyed off the chosen night. If the previously
                // selected product isn't available for the new night, the
                // dropdown falls back to the empty placeholder.
                var roomItems = night ? night.products.map(function (p) {
                    return { value: p.id, label: p.label };
                }) : [];
                fillOptions($product, config.labels.selectRoom, roomItems, $product.val());

                var productId = $product.val();
                var product = night ? findProduct(night, productId) : null;

                // Persons cell is hidden until a room is picked — otherwise
                // the dropdown sits there empty or showing a stray "—".
                $personsCell.toggleClass('hotel-field--hidden', !product);

                // Persons options keyed off the chosen product's min/max.
                // Null placeholder = no leading "— —" option.
                var personItems = [];
                if (product) {
                    for (var p = product.min; p <= product.max; p++) {
                        personItems.push({
                            value: p,
                            label: config.personLabels[String(p)] || (p + ' persons')
                        });
                    }
                }
                var prevPersons = $persons.val();
                fillOptions($persons, null, personItems, prevPersons);
                // If the previous value isn't valid for the new product,
                // default to the minimum so the cart line still has a count.
                if (product && !$persons.val()) {
                    $persons.val(String(product.min));
                }
                if (!product) {
                    $persons.val('');
                }

                // Guest blocks visibility — show one per person (1..N).
                // Threshold is < 1 so 1-person rooms also surface guest #1.
                var personsInt = parseInt($persons.val(), 10) || 0;
                $row.find('.hotel-repeater-guests')
                    .toggleClass('hotel-field--hidden', personsInt < 1 || !product);
                $row.find('.hotel-repeater-guest').each(function () {
                    var $g = $(this);
                    var idx = parseInt($g.attr('data-guest-index'), 10) || 0;
                    $g.toggleClass('hotel-field--hidden', !product || personsInt < idx);
                });
            }

            // -- container-level syncing ----------------------------------

            function syncAll() {
                $rows.children('.hotel-repeater-row').each(function () {
                    syncRow($(this));
                });
                reindexRows();
            }

            // Re-number rows' name attributes so they stay sequential after
            // add/remove. Keeps PHP's $_POST['hotel_rooms'][N] map dense.
            function reindexRows() {
                $rows.children('.hotel-repeater-row').each(function (newIdx) {
                    var $row = $(this);
                    $row.attr('data-row-index', newIdx);
                    $row.find('[name]').each(function () {
                        var name = this.getAttribute('name');
                        if (!name) { return; }
                        // hotel_rooms[<anything>] -> hotel_rooms[<newIdx>]
                        var rewritten = name.replace(/^hotel_rooms\[[^\]]*\]/, 'hotel_rooms[' + newIdx + ']');
                        if (rewritten !== name) { this.setAttribute('name', rewritten); }
                    });
                });
            }

            function rowCount() {
                return $rows.children('.hotel-repeater-row').length;
            }

            // -- add / remove --------------------------------------------

            function appendNewRow() {
                var html = $template.html();
                var $newRow = $($.parseHTML($.trim(html)));
                // Clear any pre-filled values from the template.
                $newRow.find('select').val('');
                $newRow.find('input[type="text"], input[type="email"]').val('');
                $rows.append($newRow);
                syncRow($newRow);
                // Intuitive default: the customer probably wants to fill in
                // themselves first, so seed guest #1 from the billing form.
                // They can still overwrite if booking on someone else's behalf.
                prefillGuestOneFromBilling($newRow, true);
                reindexRows();
            }

            // Read billing first/last/email and write them into the row's
            // guest #1 input fields. When force=false, only blank fields are
            // overwritten — that's used when billing changes after the row
            // already exists, so we don't clobber a manually-typed name.
            function prefillGuestOneFromBilling($row, force) {
                var bf = $('#billing_first_name').val() || '';
                var bl = $('#billing_last_name').val() || '';
                var be = $('#billing_email').val() || '';
                var $g1 = $row.find('.hotel-repeater-guest[data-guest-index="1"]');
                if (!$g1.length) { return; }
                var $first = $g1.find('.hotel-repeater-guest-first');
                var $last = $g1.find('.hotel-repeater-guest-last');
                var $email = $g1.find('.hotel-repeater-guest-email');
                if (force || !$first.val()) { $first.val(bf); }
                if (force || !$last.val()) { $last.val(bl); }
                if (force || !$email.val()) { $email.val(be); }
            }

            function prefillEmptyGuestOnesFromBilling() {
                $rows.children('.hotel-repeater-row').each(function () {
                    prefillGuestOneFromBilling($(this), false);
                });
            }

            function removeRow($row) {
                $row.remove();
                reindexRows();
                pushToCart();
            }

            // -- master toggle ------------------------------------------

            function applyMasterVisibility() {
                var on = masterChecked();
                $('#hotel_rooms_field').toggleClass('hotel-field--hidden', !on);
                if (on && rowCount() === 0) {
                    appendNewRow();
                }
                if (!on) {
                    // Clear everything when the customer changes their mind —
                    // matches the "don't remember" UX the user asked for.
                    $rows.empty();
                }
            }

            // -- AJAX push ------------------------------------------------

            function pushToCart() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function () {
                    var payload = $('form.checkout').serializeArray()
                        .filter(function (f) {
                            return f.name === 'hotel_master_interested'
                                || f.name.indexOf('hotel_rooms[') === 0;
                        });
                    payload.push({ name: 'action', value: 'alttag_update_hotel_cart' });
                    payload.push({ name: 'nonce', value: nonce });
                    $.post(ajaxUrl, $.param(payload), function () {
                        $(document.body).trigger('update_checkout');
                    });
                }, 350);
            }

            // -- event wiring --------------------------------------------

            // Master checkbox: show/hide the repeater container. Re-apply on
            // every change so removing it after re-render still works.
            $(document).on('change', '#hotel_master_interested', function () {
                applyMasterVisibility();
                pushToCart();
            });

            // When the customer types their billing name/email, mirror it
            // into any empty guest #1 fields — keeps the "fill in self"
            // shortcut working even when the master toggle was already on
            // before they typed the billing info.
            $(document).on('blur', '#billing_first_name, #billing_last_name, #billing_email', function () {
                prefillEmptyGuestOnesFromBilling();
                pushToCart();
            });

            // Delegated handlers so they survive add/remove.
            $rows.on('change', '.hotel-repeater-night, .hotel-repeater-product, .hotel-repeater-persons', function () {
                syncRow($(this).closest('.hotel-repeater-row'));
                pushToCart();
            });
            $rows.on('blur', '.hotel-repeater-guest-first, .hotel-repeater-guest-last, .hotel-repeater-guest-email', pushToCart);
            $rows.on('click', '.hotel-repeater-remove', function () {
                removeRow($(this).closest('.hotel-repeater-row'));
            });
            $addBtn.on('click', function () {
                appendNewRow();
                pushToCart();
            });

            // WC re-renders the checkout form after each update_checkout —
            // re-bind handlers on top of the rebuilt DOM.
            $(document.body).on('updated_checkout', function () {
                $container = $('#hotel_rooms_field .hotel-repeater');
                if (!$container.length) { return; }
                try { config = JSON.parse($container.attr('data-config')); } catch (e) {}
                $rows = $container.find('.hotel-repeater-rows');
                $template = $container.find('.hotel-repeater-template');
                $addBtn = $container.find('.hotel-repeater-add');
                $master = $('#hotel_master_interested');
                applyMasterVisibility();
                syncAll();
            });

            // Initial paint.
            applyMasterVisibility();
            syncAll();
        })(jQuery);
        </script>
        <?php
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function getSelectionFromSession(): array
    {
        if (!function_exists('WC') || !WC()->session) {
            return [];
        }
        $session = WC()->session->get(self::SESSION_KEY, []);
        return is_array($session) ? $session : [];
    }

    /**
     * Repeater rows the customer most recently committed (numerically indexed).
     * Tolerates the legacy keyed-by-slug session payload so a customer landing
     * back on checkout from an old session sees their previous selections.
     *
     * @return array<int, array>
     */
    private function getRowsFromSession(): array
    {
        if (!function_exists('WC') || !WC()->session) {
            return [];
        }
        $stored = WC()->session->get(self::SESSIONS_KEY, []);
        if (!is_array($stored) || empty($stored)) {
            return [];
        }
        $rows = [];
        foreach ($stored as $key => $entry) {
            if (!is_array($entry) || empty($entry['product_id'])) {
                continue;
            }
            // The legacy session shape keyed entries by night slug; the new
            // shape uses numeric keys and embeds the slug in 'night'. Either
            // way we want a numeric list with explicit 'night' fields.
            if (empty($entry['night']) && is_string($key)) {
                $entry['night'] = $key;
            }
            $rows[] = $entry;
        }
        return $rows;
    }

    /**
     * Read POSTed repeater rows ($_POST['hotel_rooms'][]) and produce a clean
     * list of selections ready for cart/order persistence. Invalid rows
     * (missing product, unknown night, mismatched product-for-night) are
     * silently dropped — validation runs separately via validateFields().
     *
     * @return array<int, array>
     */
    private function extractRowsFromPost(): array
    {
        $master = isset($_POST['hotel_master_interested'])
            && $_POST['hotel_master_interested'] === '1';
        if (!$master) {
            return [];
        }

        $raw = $_POST['hotel_rooms'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $nights = $this->getAvailableNights();
        $effective_max = $this->getEffectiveMaxPersons();
        $rows = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $night_slug = isset($entry['night'])
                ? sanitize_text_field(wp_unslash((string) $entry['night']))
                : '';
            $product_id = isset($entry['product_id']) ? (int) $entry['product_id'] : 0;

            if ($night_slug === '' || $product_id <= 0) {
                continue;
            }
            if (!isset($nights[$night_slug]['products'][$product_id])) {
                continue;
            }
            $product = $nights[$night_slug]['products'][$product_id];
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $min = $this->getProductMinPersons($product);
            $max = $this->getProductMaxPersons($product);
            $persons_raw = isset($entry['persons']) ? (int) $entry['persons'] : 0;
            $persons = $persons_raw > 0
                ? min($max, max($min, $persons_raw))
                : $min;

            // Unified guests[] payload (new shape) takes precedence; the
            // legacy flat slots (guest_first_name + extra_guests[]) are
            // accepted as a fallback so half-migrated sessions still work.
            $guests_raw = isset($entry['guests']) && is_array($entry['guests'])
                ? $entry['guests'] : [];
            $extras_raw = isset($entry['extra_guests']) && is_array($entry['extra_guests'])
                ? $entry['extra_guests'] : [];

            $guests = [];
            for ($g = 1; $g <= $effective_max; $g++) {
                if (isset($guests_raw[$g]) && is_array($guests_raw[$g])) {
                    $gsrc = $guests_raw[$g];
                } elseif ($g === 2) {
                    $gsrc = [
                        'first_name' => $entry['guest_first_name'] ?? '',
                        'last_name' => $entry['guest_last_name'] ?? '',
                        'email' => $entry['guest_email'] ?? '',
                    ];
                } elseif (isset($extras_raw[$g])) {
                    $gsrc = $extras_raw[$g];
                } else {
                    $gsrc = ['first_name' => '', 'last_name' => '', 'email' => ''];
                }

                $gf = sanitize_text_field(wp_unslash((string) ($gsrc['first_name'] ?? '')));
                $gl = sanitize_text_field(wp_unslash((string) ($gsrc['last_name'] ?? '')));
                $ge = sanitize_email(wp_unslash((string) ($gsrc['email'] ?? '')));
                $guests[$g] = [
                    'first_name' => $gf,
                    'last_name' => $gl,
                    'name' => trim($gf . ' ' . $gl),
                    'email' => $ge,
                ];
            }

            // Legacy mirrors so existing display code (renderCartItemMeta,
            // getOrderHotelInfos, buildParticipantHotelSummaryHtml) keeps
            // showing what it always did. Guest #2 fills the legacy slots;
            // guests 3+ go into the legacy extra_guests array.
            $g2 = $guests[2] ?? ['first_name' => '', 'last_name' => '', 'name' => '', 'email' => ''];
            $legacy_extras = [];
            for ($g = 3; $g <= $effective_max; $g++) {
                $legacy_extras[$g] = $guests[$g];
            }

            $rows[] = [
                'night' => $night_slug,
                'product_id' => $product_id,
                'product_name' => $product->get_name(),
                'person_count' => (string) $persons,
                // New canonical guests array — index 1..N.
                'guests' => $guests,
                // Back-compat fields:
                'guest_first_name' => $g2['first_name'],
                'guest_last_name' => $g2['last_name'],
                'guest_name' => $g2['name'],
                'guest_email' => $g2['email'],
                'extra_guests' => $legacy_extras,
                // The legacy normaliseNightSelection() returned an explicit
                // accommodation flag; keep it for downstream consumers that
                // still look at it (cart/order glue).
                'accommodation' => '1',
            ];
        }
        return $rows;
    }
}
