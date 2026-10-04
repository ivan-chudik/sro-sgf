<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages event metadata from WooCommerce product meta fields.
 * This is 100% identical across all customization plugins,
 * so it belongs in core.
 */
class EventManager
{
    const EVENT_DETAILS_META = '_event_details';

    public function registerHooks()
    {
        add_action('plugins_loaded', [$this, 'registerFilters'], 5);
        add_action('woocommerce_product_data_tabs', [$this, 'addProductTab']);
        add_action('woocommerce_product_data_panels', [$this, 'renderProductPanel']);
        add_action('woocommerce_process_product_meta', [$this, 'saveProductMeta']);
        add_shortcode('alttag-product-price', [$this, 'renderProductPriceShortcode']);
    }

    /**
     * Register event filters at priority 5 (customization overrides at 10+)
     */
    public function registerFilters()
    {
        add_filter('alttag_registrations_event_name', [$this, 'filterEventName'], 5);
        add_filter('alttag_registrations_event_name_locative', [$this, 'filterEventNameLocative'], 5);
        add_filter('alttag_registrations_event_name_accusative', [$this, 'filterEventNameAccusative'], 5);
        add_filter('alttag_registrations_event_noun', [$this, 'filterEventNoun'], 5);
        add_filter('alttag_registrations_event_noun_genitive', [$this, 'filterEventNounGenitive'], 5);
        add_filter('alttag_registrations_event_dates', [$this, 'filterEventDates'], 5);
        add_filter('alttag_registrations_enable_multi_day', [$this, 'filterMultiDay'], 5);
        add_filter('alttag_registrations_available_dates', [$this, 'filterAvailableDates'], 5);
        add_filter('alttag_registrations_event_dates_string', [$this, 'filterDatesString'], 5);
        add_filter('alttag_registrations_event_time', [$this, 'filterEventTime'], 5);
        add_filter('alttag_registrations_event_location', [$this, 'filterLocation'], 5, 2);
        add_filter('alttag_registrations_event_location_with_street', [$this, 'filterLocationWithStreet'], 5, 2);
        add_filter('alttag_registrations_event_name_with_date', [$this, 'filterNameWithDate'], 5);
        add_filter('alttag_registrations_event_name_with_year', [$this, 'filterNameWithYear'], 5);
        add_filter('alttag_registrations_receipt_title', [$this, 'filterReceiptTitle'], 5);
    }

    public function renderProductPriceShortcode($atts)
    {
        $atts = shortcode_atts([
            'product_id' => '',
            'including_tax' => 'yes',
        ], $atts, 'alttag-product-price');

        $product_id = absint($atts['product_id']);
        if (!$product_id) {
            $product = ProductConfig::current();
            $product_id = $product ? $product->id() : 0;
        }

        if (!$product_id) {
            return '';
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return '';
        }

        $including_tax = !in_array(strtolower((string) $atts['including_tax']), ['no', 'false', '0'], true);
        $price = $including_tax
            ? wc_get_price_including_tax($product)
            : wc_get_price_excluding_tax($product);

        return (string) $price;
    }

    // =========================================================================
    // Context detection
    // =========================================================================

    /**
     * Get the current product ID from context (cart, order, participant)
     *
     * @return int|null
     */
    public static function getCurrentContextProductId()
    {
        // 1. Try from cart. Only after wp_loaded: touching the cart during
        // bootstrap can raise WooCommerce notices whose translation filters
        // resolve the context from the cart again (infinite loop, OOM).
        if (did_action('wp_loaded')
            && function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                return (int) $cart_item['product_id'];
            }
        }

        // 2. Try from RegistrationContext product or order (email, receipt context)
        // Use currentOrNull() to avoid infinite loop
        // (current() calls getCurrentContextProductId())
        $ctx = RegistrationContext::currentOrNull();
        if ($ctx !== null) {
            $product_id = $ctx->productId();
            if ($product_id) {
                return (int) $product_id;
            }
            $ctx_order = $ctx->order();
            if ($ctx_order instanceof \WC_Order) {
                foreach ($ctx_order->get_items() as $item) {
                    $product = $item->get_product();
                    if ($product) {
                        return $product->get_id();
                    }
                }
            }
        }

        // 3. Try from current order (thankyou page URL)
        global $wp;
        if (function_exists('WC') && !empty($wp->query_vars['order-received'])) {
            $order = wc_get_order($wp->query_vars['order-received']);
            if ($order) {
                foreach ($order->get_items() as $item) {
                    $product = $item->get_product();
                    if ($product) {
                        return $product->get_id();
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get event meta from product with fallback
     *
     * @param string $meta_key
     * @param mixed $fallback
     * @return mixed
     */
    public static function getProductEventMeta($meta_key, $fallback = '')
    {
        $product_id = self::getCurrentContextProductId();
        if ($product_id) {
            $value = get_post_meta($product_id, $meta_key, true);
            if (!empty($value)) {
                return $value;
            }
        }
        return $fallback;
    }

    /**
     * Get event details from a product
     *
     * @param int $product_id
     * @return array
     */
    public static function getProductEventDetails($product_id)
    {
        $details = get_post_meta($product_id, self::EVENT_DETAILS_META, true);
        if (!is_array($details)) {
            return [];
        }
        return array_filter($details, function ($row) {
            return !empty($row['label']) && !empty($row['value']);
        });
    }

    /**
     * Get event details from a participant's product
     *
     * @param int $participant_id
     * @return array
     */
    public static function getParticipantEventDetails($participant_id)
    {
        $state = ParticipantState::get($participant_id);
        $product_id = $state ? $state->getMeta('product_id') : null;
        if (empty($product_id)) {
            return [];
        }
        return self::getProductEventDetails($product_id);
    }

    /**
     * Get event details from an order's product
     *
     * @param \WC_Order $order
     * @return array
     */
    public static function getOrderEventDetails($order)
    {
        if (!$order instanceof \WC_Order) {
            return [];
        }
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                return self::getProductEventDetails($product->get_id());
            }
        }
        return [];
    }

    // =========================================================================
    // Filter callbacks
    // =========================================================================

    public function filterEventName($name)
    {
        $global = Settings::getTranslatable('general.event_name');
        return self::getProductEventMeta('_event_name', $global !== '' ? $global : 'Event');
    }

    public function filterEventNameLocative($name)
    {
        $global = Settings::getTranslatable('general.event_name_locative');
        $value = self::getProductEventMeta('_event_name_locative', $global);
        return $value !== '' ? $value : $name;
    }

    public function filterEventNameAccusative($name)
    {
        $global = Settings::getTranslatable('general.event_name_accusative');
        $value = self::getProductEventMeta('_event_name_accusative', $global);
        return $value !== '' ? $value : $name;
    }

    public function filterEventNoun($noun)
    {
        $global = Settings::getTranslatable('general.event_noun');
        $value = self::getProductEventMeta('_event_noun', $global);
        if ($value !== '') {
            return $value;
        }
        return $noun !== '' ? $noun : __('podujatie', 'alttag-registrations');
    }

    public function filterEventNounGenitive($noun)
    {
        $global = Settings::getTranslatable('general.event_noun_genitive');
        $value = self::getProductEventMeta('_event_noun_genitive', $global);
        if ($value !== '') {
            return $value;
        }
        // Fall back to the nominative event_noun when no genitive form set.
        return $noun !== '' ? $noun : \Alttag\Registrations\get_event_noun();
    }

    public function filterEventDates($dates)
    {
        $start = self::getProductEventMeta('_event_date_start');
        $end = self::getProductEventMeta('_event_date_end');

        if (empty($start)) {
            $start = Settings::getTranslatable('general.event_date_start');
        }
        if (empty($end)) {
            $end = Settings::getTranslatable('general.event_date_end');
        }

        if (!empty($start)) {
            $dates['start'] = $start;
        }
        if (!empty($end)) {
            $dates['end'] = $end;
        }

        return $dates;
    }

    public function filterMultiDay($enabled)
    {
        $product_id = self::getCurrentContextProductId();
        if ($product_id) {
            $dates = get_post_meta($product_id, '_event_available_dates', true);
            return is_array($dates) && !empty($dates);
        }
        return $enabled;
    }

    public function filterAvailableDates($dates)
    {
        $product_id = self::getCurrentContextProductId();
        if (!$product_id) {
            return $dates;
        }

        $available = get_post_meta($product_id, '_event_available_dates', true);
        if (!is_array($available) || empty($available)) {
            return $dates;
        }

        $result = [];
        foreach ($available as $row) {
            if (!empty($row['date'])) {
                $result[$row['date']] = $row['label'] ?? $row['date'];
            }
        }
        return $result;
    }

    public function filterEventTime($time)
    {
        // Per-product override wins over the global Settings value — same
        // shape the other event getters follow (name, dates, venue …). Falls
        // through to the incoming $time when neither is set so a downstream
        // filter (or a caller that already supplied a value) is not clobbered.
        $product = self::getProductEventMeta('_event_time');
        if (is_string($product) && $product !== '') {
            return $product;
        }
        $global = Settings::getTranslatable('general.event_time');
        if (is_string($global) && $global !== '') {
            return $global;
        }
        return $time;
    }

    public function filterDatesString($dates_string)
    {
        $dates = get_event_dates();
        if (empty($dates['start'])) {
            return $dates_string;
        }

        if ($dates['start'] === ($dates['end'] ?? '')) {
            return date_i18n('j. n. Y', strtotime($dates['start']));
        }

        $start_ts = strtotime($dates['start']);
        $end_ts = strtotime($dates['end']);
        $same_month = date('n', $start_ts) === date('n', $end_ts)
            && date('Y', $start_ts) === date('Y', $end_ts);
        $same_year = date('Y', $start_ts) === date('Y', $end_ts);

        if ($same_month) {
            // 8 - 19. 8. 2026 — drop month/year on start
            return date_i18n('j', $start_ts) . ' - ' . date_i18n('j. n. Y', $end_ts);
        }

        if ($same_year) {
            // 8. 7. - 19. 8. 2026 — keep month on start, drop year only
            return date_i18n('j. n.', $start_ts) . ' - ' . date_i18n('j. n. Y', $end_ts);
        }

        // 28. 12. 2025 - 5. 1. 2026 — full date on both sides
        return date_i18n('j. n. Y', $start_ts) . ' - ' . date_i18n('j. n. Y', $end_ts);
    }

    public function filterLocation($location, $case = 'nominative')
    {
        $product_id = self::getCurrentContextProductId();

        if ($case === 'locative') {
            if ($product_id) {
                $locative = $this->getVenueLocative($product_id);
                if (!empty($locative)) {
                    return $locative;
                }
            }
            $global = Settings::getTranslatable('general.venue_locative');
            if (!empty($global)) {
                return $global;
            }
        }

        // Try per-product venue meta first
        if ($product_id) {
            $venue = get_post_meta($product_id, '_event_venue', true);
            if (!empty($venue)) {
                return $venue;
            }
        }

        // Fallback to global setting
        $global_venue = Settings::getTranslatable('general.venue');
        return !empty($global_venue) ? $global_venue : $location;
    }

    public function filterLocationWithStreet($location, $case = 'nominative')
    {
        $product_id = self::getCurrentContextProductId();

        if ($case === 'locative') {
            if ($product_id) {
                $locative = $this->getVenueLocative($product_id);
                if (!empty($locative)) {
                    return $locative;
                }
            }
            $global = Settings::getTranslatable('general.venue_locative');
            if (!empty($global)) {
                return $global;
            }
        }

        // Try per-product meta first
        $venue = '';
        $address = '';
        if ($product_id) {
            $venue = get_post_meta($product_id, '_event_venue', true);
            $address = get_post_meta($product_id, '_event_address', true);
        }

        // Fallback to global settings
        if (empty($venue)) {
            $venue = Settings::getTranslatable('general.venue');
        }
        if (empty($address)) {
            $address = Settings::getTranslatable('general.venue_address');
        }

        if (!empty($venue) && !empty($address)) {
            return $venue . ', ' . $address;
        }
        return $venue ?: $location;
    }

    /**
     * Get venue locative from product meta or global settings.
     */
    private function getVenueLocative($product_id)
    {
        $locative = get_post_meta($product_id, '_event_venue_locative', true);
        if (!empty($locative)) {
            return $locative;
        }

        return Settings::getTranslatable('general.venue_locative');
    }

    public function filterNameWithDate($name)
    {
        return get_event_name() . ' - ' . get_event_dates_string();
    }

    public function filterNameWithYear($name)
    {
        $event_name = get_event_name();
        $dates = get_event_dates();
        $year = !empty($dates['start']) ? date('Y', strtotime($dates['start'])) : date('Y');
        if ($event_name === '' || strpos($event_name, (string) $year) !== false) {
            return $event_name;
        }
        return $event_name . ' ' . $year;
    }

    public function filterReceiptTitle($name)
    {
        return get_event_name_with_year();
    }

    // =========================================================================
    // Product Data Tab (Event Details)
    // =========================================================================

    public function addProductTab($tabs)
    {
        $tabs['event_details'] = [
            'label' => __('Event Details', 'alttag-registrations'),
            'target' => 'event_details_product_data',
            'class' => [],
            'priority' => 65,
        ];
        return $tabs;
    }

    public function renderProductPanel()
    {
        global $post;
        $details = get_post_meta($post->ID, self::EVENT_DETAILS_META, true);
        if (!is_array($details)) {
            $details = [];
        }
        ?>
        <div id="event_details_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <?php
                // Auto-render simple product override fields from Settings definitions
                foreach (Settings::getProductOverrideFields() as $key => $field) {
                    // Complex fields (dates, tiers) render separately below
                    if (($field['type'] ?? '') === 'complex') {
                        continue;
                    }

                    $meta_key = $field['product_meta_key'];
                    $current_value = get_post_meta($post->ID, $meta_key, true);
                    $type = $field['type'] ?? 'text';

                    if ($type === 'checkbox') {
                        woocommerce_wp_checkbox([
                            'id' => $meta_key,
                            'label' => $field['label'],
                            'description' => $field['description'] ?? '',
                            'desc_tip' => true,
                            'value' => $current_value === '1' ? 'yes' : 'no',
                            'cbvalue' => 'yes',
                        ]);
                        continue;
                    }

                    $global_value = Settings::getTranslatable($key);
                    $input_args = [
                        'id' => $meta_key,
                        'label' => $field['label'],
                        'desc_tip' => true,
                        'description' => $field['description'] ?? '',
                        'value' => $current_value,
                        'placeholder' => $global_value !== '' ? $global_value : '',
                    ];

                    if ($type === 'number') {
                        $input_args['type'] = 'number';
                        $input_args['custom_attributes'] = ['min' => '0', 'step' => '1'];
                    }

                    woocommerce_wp_text_input($input_args);
                }
                ?>
            </div>

            <?php // Module per-product toggles ?>
            <div class="options_group">
                <?php
                $registry = Core::getInstance()->moduleRegistry;
                foreach ($registry->getAll() as $module) {
                    if (!$module->hasProductToggle()) {
                        continue;
                    }
                    $meta_key = '_alttag_module_' . $module->getId();
                    woocommerce_wp_select([
                        'id' => $meta_key,
                        'label' => $module->getName(),
                        'desc_tip' => true,
                        'description' => sprintf(
                            __('Global: %s', 'alttag-registrations'),
                            $module->isEnabled()
                                ? __('Enabled', 'alttag-registrations')
                                : __('Disabled', 'alttag-registrations')
                        ),
                        'options' => [
                            '' => __('Use global setting', 'alttag-registrations'),
                            '1' => __('Enabled', 'alttag-registrations'),
                            '0' => __('Disabled', 'alttag-registrations'),
                        ],
                        'value' => get_post_meta($post->ID, $meta_key, true),
                    ]);
                }

                // Companion mode (shown only when companion module is available)
                woocommerce_wp_select([
                    'id' => '_alttag_companion_mode',
                    'label' => __('Companion Mode', 'alttag-registrations'),
                    'desc_tip' => true,
                    'description' => __('Full: companion name/email fields + auto-registration. Count only: just store person count, no companion fields.', 'alttag-registrations'),
                    'options' => [
                        'full' => __('Full (companion fields + registration)', 'alttag-registrations'),
                        'count_only' => __('Count only (no companion fields)', 'alttag-registrations'),
                    ],
                    'value' => get_post_meta($post->ID, '_alttag_companion_mode', true) ?: 'full',
                ]);

                woocommerce_wp_checkbox([
                    'id' => '_alttag_hide_variable_symbol',
                    'label' => __('Hide variable symbol', 'alttag-registrations'),
                    'desc_tip' => true,
                    'description' => __('Hide variable symbol from email and ticket for this product.', 'alttag-registrations'),
                    'value' => get_post_meta($post->ID, '_alttag_hide_variable_symbol', true),
                ]);
                ?>
            </div>

            <?php
            $has_custom_dates = get_post_meta($post->ID, '_event_custom_dates', true) === '1';
            $available_dates = get_post_meta($post->ID, '_event_available_dates', true);
            if (!is_array($available_dates)) {
                $available_dates = [];
            }
            $pricing_tiers = get_post_meta($post->ID, '_pricing_tiers', true);
            if (!is_array($pricing_tiers)) {
                $pricing_tiers = [];
            }

            // If no custom dates, show global values (if any)
            $global_dates = Settings::getArrayValue('general.available_dates');
            $global_tiers = Settings::getArrayValue('general.pricing_tiers');

            // Auto-detect: if product has dates but no toggle, set toggle on
            if (!$has_custom_dates && !empty($available_dates)) {
                $has_custom_dates = true;
            }
            ?>
            <div class="options_group">
                <?php
                woocommerce_wp_checkbox([
                    'id' => '_event_custom_dates',
                    'label' => __('Custom days & pricing', 'alttag-registrations'),
                    'description' => __('Override global days & pricing for this product', 'alttag-registrations'),
                    'value' => $has_custom_dates ? 'yes' : 'no',
                    'cbvalue' => 'yes',
                ]);
                ?>

                <?php if (!$has_custom_dates && !empty($global_dates)) : ?>
                <div id="global-dates-preview" style="padding: 5px 20px; color: #888;">
                    <em><?php esc_html_e('Using global:', 'alttag-registrations'); ?></em>
                    <?php foreach ($global_dates as $row) : ?>
                        <span style="display: inline-block; margin: 2px 4px; padding: 2px 8px; background: #f0f0f0; border-radius: 3px;">
                            <?php echo esc_html(($row['label'] ?? $row['date'] ?? '') . ' — ' . ($row['price'] ?? '0') . ' ' . get_woocommerce_currency_symbol()); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div id="custom-dates-section" style="<?php echo $has_custom_dates ? '' : 'display:none;'; ?>">
                <p class="form-field">
                    <strong><?php esc_html_e('Days & Pricing', 'alttag-registrations'); ?></strong>
                    <span class="description" style="margin-left: 10px;">
                        <?php esc_html_e('Date, label and price per day per person.', 'alttag-registrations'); ?>
                    </span>
                </p>

                <div id="available-dates-rows">
                    <?php foreach ($available_dates as $i => $row) : ?>
                    <div class="available-date-row" style="display: flex; gap: 8px; padding: 5px 20px; align-items: center;">
                        <input type="text" name="_event_available_dates[<?php echo $i; ?>][date]"
                               value="<?php echo esc_attr($row['date'] ?? ''); ?>"
                               placeholder="2026-04-10" style="width: 110px;" />
                        <input type="text" name="_event_available_dates[<?php echo $i; ?>][label]"
                               value="<?php echo esc_attr($row['label'] ?? ''); ?>"
                               placeholder="<?php esc_attr_e('Friday 10.4.', 'alttag-registrations'); ?>"
                               style="width: 140px;" />
                        <input type="text" name="_event_available_dates[<?php echo $i; ?>][price]"
                               value="<?php echo esc_attr($row['price'] ?? ''); ?>"
                               placeholder="0.00" style="width: 80px; text-align: right;" />
                        <span><?php echo get_woocommerce_currency_symbol(); ?></span>
                        <button type="button" class="button available-date-remove" style="color: #a00;">&times;</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="form-field" style="padding-left: 20px;">
                    <button type="button" class="button" id="available-date-add-row">
                        + <?php esc_html_e('Add day', 'alttag-registrations'); ?>
                    </button>
                </p>

                <p class="form-field" style="padding: 0 20px; margin-top: 15px;">
                    <span class="description">
                        <strong><?php esc_html_e('Multi-day discounts', 'alttag-registrations'); ?></strong>
                        — <?php esc_html_e('optional, overrides per-day sum', 'alttag-registrations'); ?>
                    </span>
                </p>
                <div id="pricing-tiers-rows">
                    <?php foreach ($pricing_tiers as $i => $tier) : ?>
                    <div class="pricing-tier-row" style="display: flex; gap: 8px; padding: 5px 20px; align-items: center;">
                        <input type="number" name="_pricing_tiers[<?php echo $i; ?>][days]"
                               value="<?php echo esc_attr($tier['days'] ?? ''); ?>"
                               min="1" style="width: 50px;" />
                        <span><?php esc_html_e('days =', 'alttag-registrations'); ?></span>
                        <input type="text" name="_pricing_tiers[<?php echo $i; ?>][price]"
                               value="<?php echo esc_attr($tier['price'] ?? ''); ?>"
                               style="width: 80px; text-align: right;" />
                        <span><?php echo get_woocommerce_currency_symbol(); ?></span>
                        <button type="button" class="button pricing-tier-remove" style="color: #a00;">&times;</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="form-field" style="padding-left: 20px;">
                    <button type="button" class="button" id="pricing-tier-add-row">
                        + <?php esc_html_e('Add tier', 'alttag-registrations'); ?>
                    </button>
                </p>
                </div><?php // close #custom-dates-section ?>
            </div>
        </div>

        <script>
        jQuery(function($) {
            var $dc = $('#available-dates-rows'), di = <?php echo count($available_dates); ?>;
            var $tc = $('#pricing-tiers-rows'), ti = <?php echo count($pricing_tiers); ?>;
            var cur = '<?php echo esc_js(get_woocommerce_currency_symbol()); ?>';

            $('#available-date-add-row').on('click', function() {
                $dc.append('<div class="available-date-row" style="display:flex;gap:8px;padding:5px 20px;align-items:center;">'
                    + '<input type="text" name="_event_available_dates['+di+'][date]" placeholder="2026-04-10" style="width:110px" />'
                    + '<input type="text" name="_event_available_dates['+di+'][label]" placeholder="Friday 10.4." style="width:140px" />'
                    + '<input type="text" name="_event_available_dates['+di+'][price]" placeholder="0.00" style="width:80px;text-align:right" />'
                    + '<span>'+cur+'</span>'
                    + '<button type="button" class="button available-date-remove" style="color:#a00">&times;</button></div>');
                di++;
            });
            $dc.on('click', '.available-date-remove', function() { $(this).closest('.available-date-row').remove(); });

            $('#pricing-tier-add-row').on('click', function() {
                $tc.append('<div class="pricing-tier-row" style="display:flex;gap:8px;padding:5px 20px;align-items:center;">'
                    + '<input type="number" name="_pricing_tiers['+ti+'][days]" min="1" style="width:50px" />'
                    + '<span>days =</span>'
                    + '<input type="text" name="_pricing_tiers['+ti+'][price]" style="width:80px;text-align:right" />'
                    + '<span>'+cur+'</span>'
                    + '<button type="button" class="button pricing-tier-remove" style="color:#a00">&times;</button></div>');
                ti++;
            });
            $tc.on('click', '.pricing-tier-remove', function() { $(this).closest('.pricing-tier-row').remove(); });

            // Toggle custom dates section
            $('#_event_custom_dates').on('change', function() {
                var checked = $(this).is(':checked');
                $('#custom-dates-section').toggle(checked);
                $('#global-dates-preview').toggle(!checked);
            });
        });
        </script>
        <?php
    }

    public function saveProductMeta($post_id)
    {
        // Event details repeater
        $details = [];
        if (isset($_POST['_event_details']) && is_array($_POST['_event_details'])) {
            foreach ($_POST['_event_details'] as $row) {
                $label = sanitize_text_field(wp_unslash($row['label'] ?? ''));
                $value = wp_kses_post(wp_unslash($row['value'] ?? ''));
                if (!empty($label) || !empty($value)) {
                    $details[] = ['label' => $label, 'value' => $value];
                }
            }
        }
        update_post_meta($post_id, self::EVENT_DETAILS_META, $details);

        // Save product override fields from Settings definitions
        foreach (Settings::getProductOverrideFields() as $key => $field) {
            $meta_key = $field['product_meta_key'];
            $type = $field['type'] ?? 'text';

            // Checkboxes need special handling — unchecked checkbox is not in POST
            if ($type === 'checkbox') {
                $value = (isset($_POST[$meta_key]) && $_POST[$meta_key] === 'yes') ? '1' : '';
                update_post_meta($post_id, $meta_key, $value);
                continue;
            }

            if (!isset($_POST[$meta_key])) {
                continue;
            }
            $value = wp_unslash($_POST[$meta_key]);
            if ($type === 'number') {
                $value = absint($value);
            } else {
                $value = sanitize_text_field($value);
            }
            update_post_meta($post_id, $meta_key, $value);
        }

        // Save module per-product toggles
        $registry = Core::getInstance()->moduleRegistry;
        foreach ($registry->getAll() as $module) {
            if (!$module->hasProductToggle()) {
                continue;
            }
            $meta_key = '_alttag_module_' . $module->getId();
            if (isset($_POST[$meta_key])) {
                $value = sanitize_text_field($_POST[$meta_key]);
                update_post_meta($post_id, $meta_key, $value);
            }
        }

        // Companion mode
        if (isset($_POST['_alttag_companion_mode'])) {
            $mode = sanitize_text_field($_POST['_alttag_companion_mode']);
            update_post_meta($post_id, '_alttag_companion_mode', in_array($mode, ['full', 'count_only'], true) ? $mode : 'full');
        }

        // Hide variable symbol
        $hide_vs = isset($_POST['_alttag_hide_variable_symbol']) && $_POST['_alttag_hide_variable_symbol'] === 'yes';
        update_post_meta($post_id, '_alttag_hide_variable_symbol', $hide_vs ? 'yes' : '');

        // Custom dates toggle
        $has_custom = isset($_POST['_event_custom_dates']) && $_POST['_event_custom_dates'] === 'yes';
        update_post_meta($post_id, '_event_custom_dates', $has_custom ? '1' : '0');

        // Available dates with pricing (only save if custom toggle is on)
        if ($has_custom) {
            $avail_dates = [];
            if (isset($_POST['_event_available_dates']) && is_array($_POST['_event_available_dates'])) {
                foreach ($_POST['_event_available_dates'] as $row) {
                    $date = sanitize_text_field(wp_unslash($row['date'] ?? ''));
                    $label = sanitize_text_field(wp_unslash($row['label'] ?? ''));
                    $price = (float) str_replace(',', '.', $row['price'] ?? '0');
                    if (!empty($date)) {
                        $avail_dates[] = ['date' => $date, 'label' => $label, 'price' => $price];
                    }
                }
            }
            update_post_meta($post_id, '_event_available_dates', $avail_dates);
        } else {
            delete_post_meta($post_id, '_event_available_dates');
        }

        // Pricing tiers (only save if custom toggle is on)
        if ($has_custom) {
            $tiers = [];
            if (isset($_POST['_pricing_tiers']) && is_array($_POST['_pricing_tiers'])) {
                foreach ($_POST['_pricing_tiers'] as $row) {
                    $days = (int) ($row['days'] ?? 0);
                    $price = (float) str_replace(',', '.', $row['price'] ?? '0');
                    if ($days > 0 && $price > 0) {
                        $tiers[] = ['days' => $days, 'price' => $price];
                    }
                }
                usort($tiers, function ($a, $b) {
                    return $a['days'] <=> $b['days'];
                });
            }
            update_post_meta($post_id, '_pricing_tiers', $tiers);
        } else {
            delete_post_meta($post_id, '_pricing_tiers');
        }
    }
}
