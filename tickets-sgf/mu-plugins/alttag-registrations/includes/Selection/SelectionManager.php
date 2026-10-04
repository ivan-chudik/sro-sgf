<?php

namespace Alttag\Registrations\Selection;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages all selection types and their WooCommerce integration.
 *
 * Register new types:
 *   SelectionManager::register(new HotelSelection());
 */
class SelectionManager
{
    /** @var AbstractSelection[] */
    private static $types = [];

    public function registerHooks()
    {
        // Register built-in types
        self::register(new DaySelection());

        // Allow plugins to register additional types
        add_action('plugins_loaded', function () {
            do_action('alttag_registrations_register_selection_types');
        }, 15);

        // WooCommerce checkout flow
        add_action('woocommerce_checkout_update_order_review', [$this, 'saveToSession']);
        add_filter('woocommerce_before_calculate_totals', [$this, 'adjustPricing'], 20);
        add_filter('woocommerce_get_item_data', [$this, 'displayInCart'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'saveToOrderItem'], 10, 4);
        add_action('woocommerce_checkout_process', [$this, 'validateCheckout']);

        // Checkout UI
        add_action('woocommerce_before_checkout_billing_form', [$this, 'renderCheckoutUI']);
        add_action('wp_footer', [$this, 'outputJsConfig']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueSelectionAssets']);

        // Hide array meta from WooCommerce order item display
        add_filter('woocommerce_hidden_order_itemmeta', [$this, 'hideSelectionMeta']);

        // Save to participant on creation
        add_action('alttag_registrations_participant_create', [$this, 'saveToParticipant'], 3, 2);
    }

    /**
     * Hide selection meta keys from WooCommerce order item display to prevent
     * "Array to string conversion" warnings.
     */
    public function hideSelectionMeta($hidden)
    {
        foreach (self::$types as $type) {
            $hidden[] = $type->getMetaKey();
            $hidden[] = $type->getDataMetaKey();
        }
        return $hidden;
    }

    /**
     * Register a selection type
     */
    public static function register(AbstractSelection $type)
    {
        self::$types[$type->getId()] = $type;
    }

    /**
     * Get a type by ID
     */
    public static function getType($id)
    {
        return self::$types[$id] ?? null;
    }

    /**
     * Get all types
     */
    public static function getTypes()
    {
        return self::$types;
    }

    /**
     * Get active types for a product.
     *
     * Products flagged with `_alttag_skip_session_selection` get an empty
     * active-types list by default — they are sold as multi-session packages
     * where the session is picked at scan time, so no checkout selector
     * should render. Projects can refine this via the
     * `alttag_registrations_selection_is_active` filter (e.g. to opt
     * specific selection types back in, or to disable them on other criteria).
     */
    public static function getActiveForProduct($product_id)
    {
        $active = [];
        $skip_default = \Alttag\Registrations\product_skips_session_selection((int) $product_id);

        foreach (self::$types as $id => $type) {
            $is_active = !$skip_default && $type->isActiveForProduct($product_id);
            $is_active = (bool) apply_filters(
                'alttag_registrations_selection_is_active',
                $is_active,
                $id,
                $product_id,
                $type
            );
            if ($is_active) {
                $active[$id] = $type;
            }
        }
        return $active;
    }

    // =========================================================================
    // WooCommerce integration
    // =========================================================================

    public function saveToSession($posted_data)
    {
        parse_str($posted_data, $data);

        $product_ids = \Alttag\Registrations\get_cart_product_ids();

        foreach (self::$types as $type) {
            // The posted field root is always the bare key; a product-scoped
            // type nests one entry per product underneath it.
            $key = $type->getMetaKey();
            $data_key = $type->getDataMetaKey();
            $posted = is_array($data[$key] ?? null) ? $data[$key] : [];
            $posted_data_rows = is_array($data[$data_key] ?? null) ? $data[$data_key] : [];

            if ($type->isScopedByProduct()) {
                foreach ($product_ids as $product_id) {
                    $product_id = (int) $product_id;
                    self::storeSelection(
                        $type->getSessionKey($product_id),
                        $type->getDataSessionKey($product_id),
                        $posted[$product_id] ?? [],
                        $posted_data_rows[$product_id] ?? []
                    );
                }
                continue;
            }

            self::storeSelection($key, $data_key, $posted, $posted_data_rows);
        }
    }

    /** Write one selection list plus its per-option counts into the session. */
    private static function storeSelection($session_key, $data_session_key, $selections, $raw_data)
    {
        if (!is_array($selections)) {
            $selections = [];
        }
        if (!is_array($raw_data)) {
            $raw_data = [];
        }
        WC()->session->set($session_key, $selections);

        $selection_data = [];
        foreach ($selections as $sel) {
            $count = isset($raw_data[$sel]) ? max(1, (int) $raw_data[$sel]) : 1;
            $selection_data[$sel] = $count;
        }
        WC()->session->set($data_session_key, $selection_data);
    }

    public function adjustPricing($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item) {
            $product_id = (int) $cart_item['product_id'];
            $active = self::getActiveForProduct($product_id);

            // Matrix pricing: participant types with per-type day tiers × DaySelection.
            // Replaces independent per-type sums when both selections are active and tiers configured.
            $matrix_total = self::calculateMatrixPrice($product_id, $active);
            if ($matrix_total !== null) {
                $cart_item['data']->set_price(
                    \Alttag\Registrations\gross_to_wc_price($matrix_total, $cart_item['data'])
                );
                continue;
            }

            $total = 0;
            $has_pricing = false;
            $has_any_selection = false;

            foreach ($active as $type) {
                $prices = $type->getOptionPrices($product_id);
                if (!empty($prices)) {
                    $has_pricing = true;
                }

                $selections = $type->getDataFromSession($product_id);
                if (empty($selections)) {
                    continue;
                }
                $has_any_selection = true;

                if (!empty($prices)) {
                    $tiers = $type->getPricingTiers($product_id);
                    $total += $type->calculatePrice($selections, $prices, $tiers);
                }
            }

            // If the product has selection-based pricing, the price reflects the
            // user's choice: 0 until they pick something (avoids confusing
            // visitors with the bare per-day price), otherwise the computed
            // total. Validation on woocommerce_checkout_process still requires
            // a non-empty selection before the order can be placed.
            if ($has_pricing) {
                $effective_price = $has_any_selection && $total > 0 ? $total : 0;
                $cart_item['data']->set_price(
                    \Alttag\Registrations\gross_to_wc_price($effective_price, $cart_item['data'])
                );
            }
        }
    }

    /**
     * Compute matrix price: Σ (participant_type_count × type.tiers[selected_day_count]).
     * Returns null when matrix pricing does not apply (no per-type tiers, or
     * required selections missing), letting the caller fall back to per-type sums.
     */
    private static function calculateMatrixPrice($product_id, $active)
    {
        $type_selection = $active['participant_types'] ?? null;
        $types_meta = $type_selection && method_exists($type_selection, 'getTypes')
            ? $type_selection->getTypes($product_id)
            : get_post_meta($product_id, '_participant_types', true);
        if (!is_array($types_meta) || empty($types_meta)) {
            return null;
        }

        $has_tiers = false;
        foreach ($types_meta as $type) {
            if (!empty($type['tiers']) && is_array($type['tiers'])) {
                $has_tiers = true;
                break;
            }
        }
        if (!$has_tiers) {
            return null;
        }

        $day_selection = $active['days'] ?? null;
        if (!$day_selection || !$type_selection) {
            return null;
        }

        $selected_days = $day_selection->getFromSession($product_id);
        $type_counts = $type_selection->getDataFromSession($product_id);
        if (empty($selected_days) || empty($type_counts)) {
            return 0.0;
        }

        $day_count = count($selected_days);
        $total = 0.0;
        foreach ($types_meta as $type) {
            $id = $type['id'] ?? '';
            $count = (int) ($type_counts[$id] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $tier_price = null;
            if (!empty($type['tiers']) && is_array($type['tiers'])) {
                if (isset($type['tiers'][$day_count])) {
                    $tier_price = (float) $type['tiers'][$day_count];
                } else {
                    $best = null;
                    foreach ($type['tiers'] as $days => $price) {
                        if ((int) $days <= $day_count && ($best === null || (int) $days > $best)) {
                            $best = (int) $days;
                            $tier_price = (float) $price;
                        }
                    }
                }
            }
            if ($tier_price === null) {
                $tier_price = (float) ($type['price'] ?? 0);
            }
            $total += $count * $tier_price;
        }
        return $total;
    }

    public function displayInCart($item_data, $cart_item)
    {
        $product_id = (int) $cart_item['product_id'];

        foreach (self::getActiveForProduct($product_id) as $type) {
            $selections = $type->getFromSession($product_id);
            $data = $type->getDataFromSession($product_id);

            if (empty($selections)) {
                continue;
            }

            $formatted = $type->formatSelections($selections, $data, $product_id);
            if (!empty($formatted)) {
                $item_data[] = [
                    'name' => $type->getLabel(),
                    'value' => $formatted,
                ];
            }
        }

        return $item_data;
    }

    public function saveToOrderItem($item, $cart_item_key, $values, $order)
    {
        $product_id = (int) $values['product_id'];

        foreach (self::getActiveForProduct($product_id) as $type) {
            $selections = $type->getFromSession($product_id);
            $data = $type->getDataFromSession($product_id);

            if (!empty($selections)) {
                $item->add_meta_data($type->getMetaKey(), $selections);
            }
            if (!empty($data)) {
                $item->add_meta_data($type->getDataMetaKey(), $data);
            }
        }
    }

    public function saveToParticipant($participant_id, $order_data)
    {
        $order_id = $order_data['order_id'] ?? null;
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // With per-product selections the order items no longer carry the same
        // options, so the participant must only take the item it belongs to.
        $participant_product_id = (int) ($order_data['product_id']
            ?? get_post_meta($participant_id, 'product_id', true));

        // Prefer the exact line item when known — product_id alone cannot tell
        // two line items of the same product apart.
        $participant_item_id = (int) ($order_data['order_item_id']
            ?? get_post_meta($participant_id, 'order_item_id', true));

        foreach ($order->get_items() as $item_id => $item) {
            if ($participant_item_id > 0) {
                if ((int) $item_id !== $participant_item_id) {
                    continue;
                }
            } elseif ($participant_product_id > 0
                && (int) $item->get_product_id() !== $participant_product_id) {
                continue;
            }

            foreach (self::$types as $type) {
                $selections = $item->get_meta($type->getMetaKey());
                $data = $item->get_meta($type->getDataMetaKey());

                if (!empty($selections) && is_array($selections)) {
                    update_post_meta($participant_id, $type->getMetaKey(), $selections);
                }
                if (!empty($data) && is_array($data)) {
                    update_post_meta($participant_id, $type->getDataMetaKey(), $data);
                }
            }
        }
    }

    public function validateCheckout()
    {
        foreach (\Alttag\Registrations\get_cart_product_ids() as $product_id) {
            foreach (self::getActiveForProduct($product_id) as $type) {
                $selections = $type->getFromSession($product_id);
                $result = $type->validate($selections, $product_id);

                if (is_wp_error($result)) {
                    wc_add_notice($result->get_error_message(), 'error');
                }
            }
        }
    }

    /**
     * The selection UI, inside the one node that can be swapped for a fresh one.
     *
     * Switching the event at checkout replaces this whole block rather than
     * reloading the page (ProductGroup::handleSwitch), so it has to be a single
     * addressable node and its markup has to come from one place - here.
     */
    public function renderCheckoutUI()
    {
        echo '<div id="alttag-selection-ui">' . self::renderCheckoutUIHtml() . '</div>';
    }

    /**
     * Markup that takes the place of the standard selection UI, or null.
     *
     * A project whose products are configured somewhere else (a landing page
     * configurator, a package deal) can return its own markup - an empty
     * string included - and the standard pickers, their JS config and their
     * assets all stand down together, on the checkout page and on the event
     * switch alike. Everything behind the UI (session, pricing, order meta,
     * validation) is untouched: the replacement markup is responsible for
     * posting the selection fields the standard UI would have posted.
     */
    private static function checkoutUiOverride()
    {
        $override = apply_filters('alttag_registrations_checkout_selection_ui', null);
        return is_string($override) ? $override : null;
    }

    /** What sits inside #alttag-selection-ui, so the switch can send it back. */
    public static function renderCheckoutUIHtml(): string
    {
        $override = self::checkoutUiOverride();
        if ($override !== null) {
            return $override;
        }

        ob_start();

        // Count cart products that produce a selection UI of each type so we
        // can prefix headings with the product name when there's ambiguity.
        $product_ids = \Alttag\Registrations\get_cart_product_ids();
        $renders_per_type = [];
        foreach ($product_ids as $pid) {
            foreach (self::getActiveForProduct($pid) as $type) {
                $renders_per_type[$type->getId()] = ($renders_per_type[$type->getId()] ?? 0) + 1;
            }
        }

        foreach ($product_ids as $product_id) {
            foreach (self::getActiveForProduct($product_id) as $type) {
                $product_label = '';
                if (($renders_per_type[$type->getId()] ?? 0) > 1) {
                    $product = wc_get_product($product_id);
                    $product_label = $product ? (string) $product->get_name() : '';
                }
                $type->renderCheckoutUI(
                    $product_id,
                    $type->getFromSession($product_id),
                    $type->getDataFromSession($product_id),
                    $product_label
                );
            }
        }

        return (string) ob_get_clean();
    }

    public function outputJsConfig()
    {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        $configs = self::selectionConfigsArray();

        if (!empty($configs)) {
            echo '<script>var selectionConfigs = ' . wp_json_encode($configs) . ';</script>';
        }
    }

    /**
     * The per-type JS config of everything in the cart, keyed by type id.
     *
     * Printed into the page by outputJsConfig() and sent again by the event
     * switch, which replaces the rendered UI without a reload and so has to
     * hand the scripts the config that goes with the new markup.
     */
    public static function selectionConfigsArray(): array
    {
        // No standard markup, nothing for the selection scripts to drive.
        if (self::checkoutUiOverride() !== null) {
            return [];
        }

        $configs = [];
        foreach (\Alttag\Registrations\get_cart_product_ids() as $product_id) {
            foreach (self::getActiveForProduct($product_id) as $type) {
                $configs[$type->getId()] = $type->getJsConfig($product_id);
            }
        }
        return $configs;
    }

    // =========================================================================
    // Asset enqueue
    // =========================================================================

    /**
     * Enqueue CSS/JS for active selection types on checkout page.
     * Only loads assets for types that are active for products in cart.
     */
    public function enqueueSelectionAssets()
    {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        if (self::checkoutUiOverride() !== null) {
            return;
        }

        $active_types = $this->getActiveTypesForCart();
        if (empty($active_types)) {
            return;
        }

        $plugin_url = defined('ALTTAG_REGISTRATIONS_URL') ? ALTTAG_REGISTRATIONS_URL : '';
        $plugin_path = defined('ALTTAG_REGISTRATIONS_PATH') ? ALTTAG_REGISTRATIONS_PATH : '';

        foreach ($active_types as $type) {
            $assets = $type->getAssets();

            if (!empty($assets['css'])) {
                foreach ($assets['css'] as $css) {
                    $file_path = $plugin_path . '/assets/' . $css;
                    $handle = 'alttag-selection-' . $type->getId() . '-' . basename($css, '.css');
                    wp_enqueue_style(
                        $handle,
                        $plugin_url . 'assets/' . $css,
                        [],
                        file_exists($file_path) ? filemtime($file_path) : null
                    );
                }
            }

            if (!empty($assets['js'])) {
                foreach ($assets['js'] as $js) {
                    $file_path = $plugin_path . '/assets/' . $js;
                    $handle = 'alttag-selection-' . $type->getId() . '-' . basename($js, '.js');
                    wp_enqueue_script(
                        $handle,
                        $plugin_url . 'assets/' . $js,
                        ['jquery'],
                        file_exists($file_path) ? filemtime($file_path) : null,
                        true
                    );
                }
            }
        }
    }

    /**
     * Get selection types that are active for any product in the current cart.
     *
     * @return AbstractSelection[]
     */
    private function getActiveTypesForCart(): array
    {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return [];
        }

        $active = [];
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = (int) $cart_item['product_id'];
            foreach (self::$types as $id => $type) {
                if (!isset($active[$id]) && $type->isActiveForProduct($product_id)) {
                    $active[$id] = $type;
                }
            }
        }
        return $active;
    }

    // =========================================================================
    // Centralized data access helpers
    // =========================================================================

    /**
     * Extract all selection data from an order item into a flat array.
     * Replaces hardcoded $item->get_meta('selected_days') calls.
     *
     * @param \WC_Order_Item $item
     * @return array e.g. ['selected_days' => [...], 'selected_days_data' => [...]]
     */
    public static function flattenForOrderData($item): array
    {
        $order_data = [];
        foreach (self::$types as $type) {
            $selections = $item->get_meta($type->getMetaKey());
            if (!empty($selections) && is_array($selections)) {
                $order_data[$type->getMetaKey()] = $selections;
            }
            $data = $item->get_meta($type->getDataMetaKey());
            if (!empty($data) && is_array($data)) {
                $order_data[$type->getDataMetaKey()] = $data;
            }
        }
        return $order_data;
    }

    /**
     * Validate raw selection input against a product's available options.
     * Replaces manual validation in WebhookImport and similar.
     *
     * @param string $type_id Selection type ID (e.g. 'days')
     * @param array $raw Raw input values
     * @param int $product_id Product ID
     * @return array Validated values
     */
    public static function validateSelectionsForProduct(string $type_id, array $raw, int $product_id): array
    {
        $type = self::getType($type_id);
        if (!$type || !$type->isActiveForProduct($product_id)) {
            return [];
        }
        $available = array_keys($type->getAvailableOptions($product_id));
        return array_values(array_filter(
            array_map('sanitize_text_field', $raw),
            function ($item) use ($available) {
                return in_array($item, $available, true);
            }
        ));
    }

    /**
     * Save selection data to participant meta for all types.
     * Replaces direct update_post_meta calls with hardcoded keys.
     *
     * @param int $participant_id
     * @param array $data Flat array like ['selected_days' => [...], 'selected_days_data' => [...]]
     */
    public static function saveSelectionsToParticipant(int $participant_id, array $data): void
    {
        foreach (self::$types as $type) {
            $meta_key = $type->getMetaKey();
            $data_key = $type->getDataMetaKey();

            if (isset($data[$meta_key]) && is_array($data[$meta_key])) {
                update_post_meta($participant_id, $meta_key, $data[$meta_key]);
            }
            if (isset($data[$data_key]) && is_array($data[$data_key])) {
                update_post_meta($participant_id, $data_key, $data[$data_key]);
            }
        }
    }

    /**
     * Get all meta keys managed by selection types.
     * Useful for hiding from WooCommerce display, export, etc.
     *
     * @return array
     */
    public static function getAllMetaKeys(): array
    {
        $keys = [];
        foreach (self::$types as $type) {
            $keys[] = $type->getMetaKey();
            $keys[] = $type->getDataMetaKey();
        }
        return $keys;
    }
}
