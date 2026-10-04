<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages checkout field rendering, saving, and validation
 * using FieldBuilder definitions.
 */
class CheckoutManager
{
    /** Native billing address keys and the FieldBuilder system key that controls them. */
    public const NATIVE_ADDRESS_FIELD_MAP = [
        'billing_address_1' => 'street',
        'billing_postcode'  => 'zip',
        'billing_city'      => 'city',
        'billing_country'   => 'country',
    ];

    public function registerHooks()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);

        // Fields
        add_filter('the_title', [$this, 'filterOrderReceivedTitle'], 10, 2);
        add_filter('woocommerce_checkout_fields', [$this, 'addCheckoutFields'], 5);
        add_filter('woocommerce_checkout_fields', [$this, 'maybeMoveOrderNotesToBilling'], 20);
        add_filter('wc_stripe_elements_options', [$this, 'maybeApplyStripePrimaryColor']);
        add_action('woocommerce_before_checkout_billing_form', [$this, 'maybeRenderRegistrationProductBanner'], 5);
        add_action('woocommerce_checkout_process', [$this, 'validateDuplicateEmailRegistrations']);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'saveCheckoutFields']);

        // Admin billing (priority 15 to run after SuperFaktúra's priority 10)
        add_filter('woocommerce_admin_billing_fields', [$this, 'addAdminBillingFields'], 15);

        // Email order meta
        add_filter('woocommerce_email_order_meta_fields', [$this, 'addEmailOrderFields'], 5, 3);

        // CSS & JS from field definitions
        add_action('wp_head', [$this, 'outputFieldCss']);
        add_action('wp_head', [$this, 'outputCheckoutCustomCss'], 99);
        add_action('wp_footer', [$this, 'outputFieldJs']);
        add_action('wp_footer', [$this, 'outputMultiDayConfigScript']);

        // Variable symbol prefix from product SKU
        add_filter('alttag_registrations_zero_total_variable_symbol_prefix', [$this, 'variableSymbolPrefix'], 5);
    }

    public function enqueueAssets()
    {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        $js_file = ALTTAG_REGISTRATIONS_PATH . 'assets/js/checkout-fields.js';
        $js_version = file_exists($js_file) ? filemtime($js_file) : '1.0.0';

        wp_enqueue_script(
            'alttag-registrations-checkout-fields',
            ALTTAG_REGISTRATIONS_URL . 'assets/js/checkout-fields.js',
            ['jquery'],
            $js_version,
            true
        );
    }

    public function filterOrderReceivedTitle($title, $post_id = 0)
    {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-received')) {
            return $title;
        }

        if ((int) $post_id !== (int) get_option('woocommerce_checkout_page_id')) {
            return $title;
        }

        return '';
    }

    public function validateDuplicateEmailRegistrations()
    {
        if (!apply_filters('alttag_registrations_enable_multi_day_duplicate_validation', true)) {
            return;
        }

        $context = ctx();
        $cart = $context->cart();
        if ($cart->isEmpty) {
            return;
        }

        $email = sanitize_email($_POST['billing_email'] ?? '');
        if ($email === '') {
            return;
        }

        $day_type = $context->selection('days');

        foreach ($cart->productIds as $product_id) {
            // Days are kept per product, so the overlap check has to read the
            // selection of the product it is currently looking at.
            $selected_days = $day_type ? $day_type->getFromSession($product_id) : [];

            // Per-product skip check (allows overrides via filter or product meta)
            if (apply_filters('alttag_registrations_skip_email_duplicate_check', false, $product_id)) {
                continue;
            }
            $product_ids = $this->getEquivalentProductIds($product_id);
            if (empty($product_ids)) {
                continue;
            }

            // Products that don't render a day picker (multi-session / "all
            // sessions" tickets) can't be deduplicated by day overlap — the
            // current cart has no selected days at all. Treat any prior
            // matching participant as a duplicate.
            $product_skips_selection = product_skips_session_selection($product_id);

            $existing_participants = get_posts([
                'post_type' => 'participant',
                'meta_query' => [
                    'relation' => 'AND',
                    ['key' => 'email', 'value' => $email, 'compare' => '='],
                    ['key' => 'product_id', 'value' => array_map('strval', $product_ids), 'compare' => 'IN'],
                ],
                'numberposts' => -1,
                'fields' => 'ids',
            ]);

            if (empty($existing_participants)) {
                continue;
            }

            foreach ($existing_participants as $participant_id) {
                $state = ParticipantState::get($participant_id);
                if (!$state || !$this->isActiveRegistration($state)) {
                    continue;
                }

                $existing_days = $state->selectedDays();
                if (empty($existing_days) || $product_skips_selection) {
                    wc_add_notice(
                        sprintf(
                            __('Email %s is already registered for this product. Please use a different email address.', 'alttag-registrations'),
                            $email
                        ),
                        'error'
                    );
                    return;
                }

                $overlap = array_intersect($selected_days, $existing_days);
                if (empty($overlap)) {
                    continue;
                }

                wc_add_notice(
                    sprintf(
                        __('Email %1$s is already registered for: %2$s. Please select different days or use a different email.', 'alttag-registrations'),
                        $email,
                        implode(', ', $this->getDateLabels($overlap))
                    ),
                    'error'
                );
                return;
            }
        }
    }

    /**
     * Add custom checkout fields from FieldBuilder
     */
    public function addCheckoutFields($fields)
    {
        $custom_fields = FieldBuilder::getCheckoutFields();

        foreach ($custom_fields as $field_key => $field_config) {
            $fields['billing'][$field_key] = $field_config;
        }

        // Apply FieldBuilder system-field config (label, required, class,
        // priority) to standard WooCommerce billing fields so admins can
        // re-order/re-classify them from the FieldBuilder admin without
        // editing PHP. Address block (street/postcode/city/country) is
        // included so admins can move the whole block in the FieldBuilder
        // UI — otherwise their priority tweaks there had no effect and
        // callers had to hardcode overrides in customization plugins.
        $system_field_map = [
            'billing_phone'      => 'phone',
            'billing_first_name' => 'first_name',
            'billing_last_name'  => 'last_name',
            'billing_email'      => 'email',
            'billing_address_1'  => 'street',
            'billing_postcode'   => 'zip',
            'billing_city'       => 'city',
            'billing_country'    => 'country',
        ];
        foreach ($system_field_map as $billing_key => $fb_key) {
            $fields = $this->applySystemFieldConfigToBillingField($fields, $fb_key, $billing_key);

            // applySystemFieldConfigToBillingField copies label / required /
            // class. Priority isn't copied there (some plugins set their own),
            // so apply it explicitly as well.
            if (isset($fields['billing'][$billing_key])) {
                $fb_field = FieldBuilder::getField($fb_key);
                if ($fb_field) {
                    $fields['billing'][$billing_key]['priority'] = $fb_field['priority'] ?? 99;
                }
            }
        }

        // billing_email class is inherited from FieldBuilder above (via
        // applySystemFieldConfigToBillingField). Do NOT force form-row-wide
        // here — that overrides site customizations that want to pair email
        // with another 50%-wide field (e.g. job title) on the same row.

        // Native address fields follow the FieldBuilder "Show in -> Checkout"
        // checkbox of the system field that controls them.
        $hidden = self::getHiddenBillingFields();
        foreach ($hidden as $field_key) {
            unset($fields['billing'][$field_key]);
        }

        $required = apply_filters(
            'alttag_registrations_required_native_billing_fields',
            ['billing_address_1', 'billing_postcode', 'billing_city']
        );
        foreach ((array) $required as $field_key) {
            if (isset($fields['billing'][$field_key]) && !in_array($field_key, $hidden, true)) {
                $fields['billing'][$field_key]['required'] = true;
            }
        }

        return $fields;
    }

    /**
     * Native billing keys to remove from the checkout form.
     *
     * Each native field follows the "Show in -> Checkout" checkbox of its
     * FieldBuilder counterpart. A field without an explicit contexts array stays
     * visible. billing_address_2 has no own FieldBuilder key and follows street.
     *
     * Single source of truth, WooCommerceManager calls this too.
     *
     * @return string[]
     */
    public static function getHiddenBillingFields(): array
    {
        $hidden = [];
        foreach (self::NATIVE_ADDRESS_FIELD_MAP as $billing_key => $fb_key) {
            $fb_field = FieldBuilder::getField($fb_key);
            if (!$fb_field || !isset($fb_field['contexts']) || !is_array($fb_field['contexts'])) {
                continue;
            }
            if (!in_array('checkout', $fb_field['contexts'], true)) {
                $hidden[] = $billing_key;
                if ($billing_key === 'billing_address_1') {
                    $hidden[] = 'billing_address_2';
                }
            }
        }

        return apply_filters('alttag_registrations_hidden_native_billing_fields', $hidden);
    }

    /**
     * Move WooCommerce "order notes" field from the side column into the
     * billing column when Settings.checkout.move_order_notes_to_billing is on.
     */
    public function maybeMoveOrderNotesToBilling($fields)
    {
        if (!Settings::getValue('checkout.move_order_notes_to_billing')) {
            return $fields;
        }

        if (isset($fields['order']['order_comments'])) {
            $fields['billing']['order_comments'] = $fields['order']['order_comments'];
            $fields['billing']['order_comments']['priority'] = 100;
            unset($fields['order']['order_comments']);
        }

        return $fields;
    }

    /**
     * Output custom CSS (Settings.checkout.custom_css) on the checkout page.
     */
    public function outputCheckoutCustomCss()
    {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        $css = Settings::getValue('checkout.custom_css');
        if ($css === '') {
            return;
        }

        echo "<style>\n" . $css . "\n</style>\n";
    }

    /**
     * Render an info banner above the billing form indicating which product
     * the visitor is registering for (e.g. "Registrácia na: Ročné členské").
     * Banner template comes from Settings.checkout.registration_banner_template
     * (supports {product_name}). Hidden when template is empty.
     */
    /** Is every product in the cart an online / livestream one? */
    private static function onlyOnlineProductsInCart(): bool
    {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }

        $items = WC()->cart->get_cart();
        if (!$items) {
            return false;
        }

        foreach ($items as $cart_item) {
            $product = $cart_item['data'] ?? null;
            if (!$product instanceof \WC_Product) {
                continue;
            }
            if (!\Alttag\Registrations\product_is_livestream($product)) {
                return false;
            }
        }

        return true;
    }

    public function maybeRenderRegistrationProductBanner()
    {
        // Host projects that don't want the banner at all can switch it off in
        // code, so the decision survives a redeploy of the settings.
        if (!apply_filters('alttag_registrations_enable_registration_banner', true)) {
            return;
        }

        $template = Settings::getValue('checkout.registration_banner_template');
        if ($template === '') {
            return;
        }

        $cart = ctx()->cart();
        if ($cart->isEmpty) {
            return;
        }

        // The banner talks about a date and a venue, which an online stream has
        // neither of. A mixed cart keeps it, the in-person part still needs it.
        if (self::onlyOnlineProductsInCart()) {
            return;
        }

        $product_names = [];
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'] ?? null;
            if ($product instanceof \WC_Product) {
                $product_names[] = $product->get_name();
            }
        }

        if (empty($product_names)) {
            return;
        }

        // Nazov podujatia a datum beriem z prveho registracneho produktu v
        // kosiku; v jednej objednavke sa registruje na jedno podujatie.
        $event_name = '';
        $event_date = '';
        foreach (WC()->cart->get_cart() as $cart_item) {
            $pid = (int) ($cart_item['product_id'] ?? 0);
            if (!$pid) {
                continue;
            }
            if ($event_name === '') {
                $event_name = Settings::getValue('general.event_name', $pid);
            }
            if ($event_date === '') {
                $dates = get_post_meta($pid, '_session_dates', true);
                if (is_array($dates) && !empty($dates[0]['date'])) {
                    $event_date = format_date((string) $dates[0]['date']);
                }
            }
        }

        // Miesto konania: nastavenie produktu, s fallbackom na label terminu
        // (tam ho drzi session_location, ktory sa tlaci aj na vstupenku).
        $venue = '';
        $venue_address = '';
        foreach (WC()->cart->get_cart() as $cart_item) {
            $pid = (int) ($cart_item['product_id'] ?? 0);
            if (!$pid) {
                continue;
            }
            if ($venue === '') {
                $venue = Settings::getValue('general.venue', $pid);
            }
            if ($venue_address === '') {
                $venue_address = Settings::getValue('general.venue_address', $pid);
            }
            if ($venue === '') {
                $dates = get_post_meta($pid, '_session_dates', true);
                if (is_array($dates) && !empty($dates[0]['label'])) {
                    $venue = (string) $dates[0]['label'];
                }
            }
        }

        $rendered = str_replace(
            [
                '{product_name}', '{event_name}', '{event_date}',
                '{venue}', '{venue_address}',
            ],
            [
                implode(', ', $product_names), $event_name, $event_date,
                $venue, $venue_address,
            ],
            $template
        );

        echo '<div class="alttag-registration-banner">' . wp_kses_post($rendered) . '</div>';
    }

    /**
     * Apply primary color to Stripe Elements appearance from Settings.
     */
    public function maybeApplyStripePrimaryColor($options)
    {
        $color = Settings::getValue('checkout.stripe_primary_color');
        if ($color === '') {
            return $options;
        }

        $existing = isset($options['appearance']['variables']) && is_array($options['appearance']['variables'])
            ? $options['appearance']['variables']
            : [];

        $options['appearance'] = [
            'variables' => array_merge($existing, ['colorPrimary' => $color]),
        ];

        return $options;
    }

    private function applySystemFieldConfigToBillingField(array $fields, $system_field_key, $billing_field_key)
    {
        if (empty($fields['billing'][$billing_field_key])) {
            return $fields;
        }

        $field_definition = FieldBuilder::getField($system_field_key);
        if (!is_array($field_definition)) {
            return $fields;
        }

        $required = !empty($field_definition['required']);

        // An explicitly saved WooCommerce phone setting takes precedence.
        // Sites without the option keep their existing FieldBuilder behavior.
        if ($billing_field_key === 'billing_phone') {
            $phone_field_setting = get_option('woocommerce_checkout_phone_field', null);
            if ($phone_field_setting !== null) {
                $required = $phone_field_setting === 'required';
            }
        }

        $fields['billing'][$billing_field_key]['required'] = $required;

        if (!empty($field_definition['labels']) || !empty($field_definition['label'])) {
            $fields['billing'][$billing_field_key]['label'] = FieldBuilder::getFieldLabel($field_definition);
        }

        if (!empty($field_definition['class']) && is_array($field_definition['class'])) {
            $fields['billing'][$billing_field_key]['class'] = $field_definition['class'];
        }

        return $fields;
    }

    /**
     * Save custom field values to order meta
     */
    public function saveCheckoutFields($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $posted_data = $this->getPostedCheckoutData();

        foreach (FieldBuilder::getCheckoutFieldDefinitions() as $field_id => $field_def) {
            $save_format = $field_def['save_format'] ?? null;
            // The checkout input name is the field's meta key, not its id:
            // FieldBuilder::getCheckoutFields() keys the form fields by 'key'.
            // Reading $_POST by id silently dropped every field whose key is
            // prefixed (billing_chamber_number, …). The id stays as a fallback
            // for templates that still post the bare id.
            $meta_key = $field_def['key'] ?? $field_id;
            $posted_value = $posted_data[$meta_key] ?? $posted_data[$field_id] ?? null;
            $is_checked = $this->isTruthyPostedValue($posted_value);

            if ($save_format === 'yes_no') {
                $order->update_meta_data($meta_key, $is_checked ? 'yes' : 'no');
                continue;
            }

            if ($save_format === 'checkbox') {
                $order->update_meta_data($meta_key, $is_checked ? '1' : '0');
                continue;
            }

            if ($posted_value !== null) {
                $order->update_meta_data($meta_key, $this->sanitizePostedValue($posted_value));
            }
        }

        // The SuperFaktúra business toggle (#wi_as_company) is rendered by the
        // SF plugin, so it never appears in getCheckoutFieldDefinitions() above.
        // Nothing else persisted it on the order either: WC_Checkout::create_order
        // only auto-saves custom checkout fields whose key starts with billing_ /
        // shipping_, and SF's own checkout_order_meta() writes the flag to the
        // customer profile, not to the order. The participant copy in
        // WooCommerceManager::getOrderData() therefore had nothing to read and the
        // "Fakturovať na firmu" export column stayed empty for every registration.
        $sf_toggle = FieldBuilder::getField('wi_as_company');
        if ($sf_toggle) {
            $order->update_meta_data(
                $sf_toggle['key'] ?? 'billing_wi_as_company',
                $this->isTruthyPostedValue($posted_data['wi_as_company'] ?? null) ? '1' : '0'
            );
        }

        // Sync FieldBuilder values to WooCommerce native billing fields
        $native_map = [
            'street' => 'set_billing_address_1',
            'city' => 'set_billing_city',
            'zip' => 'set_billing_postcode',
            'country' => 'set_billing_country',
        ];

        foreach ($native_map as $custom_key => $setter) {
            $value = $posted_data[$custom_key] ?? '';
            if ($value !== '') {
                $order->$setter($value);
            }
        }

        $order->save();
    }

    /**
     * Add custom fields to admin billing section
     *
     * Runs at priority 15 (after SuperFaktúra at 10) to deduplicate
     * SF fields and apply FieldBuilder ordering to all fields.
     */
    public function addAdminBillingFields($billing_fields)
    {
        $fb_fields = FieldBuilder::getAdminBillingFields();

        // Remove SF fields already added by the SF plugin — FieldBuilder provides them
        $sf_keys = ['company_wi_id', 'company_wi_tax', 'company_wi_vat'];
        foreach ($sf_keys as $sf_key) {
            if (isset($fb_fields[$sf_key])) {
                unset($billing_fields[$sf_key]);
            }
        }

        return array_merge($billing_fields, $fb_fields);
    }

    /**
     * Add custom fields to order emails
     */
    public function addEmailOrderFields($fields, $sent_to_admin, $order)
    {
        return array_merge($fields, FieldBuilder::getEmailFields($order));
    }

    /**
     * Output CSS from FieldBuilder for conditional fields
     */
    public function outputFieldCss()
    {
        $css = FieldBuilder::generateCss();
        if (!empty($css)) {
            echo '<style>' . "\n" . $css . "\n" . '</style>';
        }
    }

    /**
     * Output JS configuration for field interactions
     */
    public function outputFieldJs()
    {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        $js_config = FieldBuilder::getJavaScriptConfig();

        // Add nonces for AJAX configurations
        foreach ($js_config['ajax_configs'] as $field_id => $config) {
            if (isset($config['nonce_action'])) {
                $js_config['ajax_configs'][$field_id]['nonce'] = wp_create_nonce($config['nonce_action']);
            }
        }

        ?>
        <script type="text/javascript">
            var checkoutFieldsConfig = <?php echo wp_json_encode($js_config); ?>;
        </script>
        <?php
    }

    public function outputMultiDayConfigScript()
    {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        $config = $this->getMultiDayConfig();
        if ($config === null) {
            return;
        }
        ?>
        <script type="text/javascript">
            var multiDayConfig = <?php echo wp_json_encode($config); ?>;
        </script>
        <?php
    }

    private function getPostedCheckoutData()
    {
        $posted_data = [];

        if (isset($_POST['post_data']) && is_string($_POST['post_data'])) {
            parse_str(wp_unslash($_POST['post_data']), $posted_data);
        }

        foreach ($_POST as $key => $value) {
            if ($key === 'post_data') {
                continue;
            }

            $posted_data[$key] = is_string($value) ? wp_unslash($value) : $value;
        }

        return $posted_data;
    }

    private function isTruthyPostedValue($value)
    {
        return in_array((string) $value, ['1', 'yes', 'true', 'on'], true);
    }

    private function sanitizePostedValue($value)
    {
        if (is_array($value)) {
            return array_map([$this, 'sanitizePostedValue'], $value);
        }

        return sanitize_text_field((string) $value);
    }

    private function getEquivalentProductIds($product_id)
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return [];
        }

        $sku = $product->get_sku();
        if ($sku === '') {
            return [(int) $product_id];
        }

        global $wpdb;
        $all_product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s",
            $sku
        ));

        if (empty($all_product_ids)) {
            return [(int) $product_id];
        }

        return array_map('intval', $all_product_ids);
    }

    private function isActiveRegistration(ParticipantState $state)
    {
        $order = $state->order();
        if (!$order) {
            return true;
        }

        return in_array($order->get_status(), ['completed', 'processing'], true);
    }

    private function getDateLabels(array $dates)
    {
        $available_dates = ctx()->availableDates();
        $labels = [];

        foreach ($dates as $date) {
            $labels[] = $available_dates[$date] ?? $date;
        }

        return $labels;
    }

    private function getMultiDayConfig()
    {
        $context = ctx();
        $available_dates = $context->availableDates();
        if (empty($available_dates)) {
            return null;
        }

        $day_type = $context->selection('days');
        if (!$day_type) {
            return null;
        }

        $day_prices = [];
        $tiers = [];
        $is_livestream = false;

        foreach ($context->cart()->productIds as $product_id) {
            $config = ProductConfig::get($product_id);
            if (!$config || !$config->hasDayPricing()) {
                continue;
            }

            $day_prices = $config->dayPrices();
            $tiers = $config->pricingTiers();
            $is_livestream = $config->isLivestream();
            break;
        }

        if (empty($day_prices)) {
            return null;
        }

        $tiers_js = new \stdClass();
        foreach ($tiers as $days => $price) {
            $tiers_js->{$days} = $price;
        }

        return [
            'day_prices' => (object) $day_prices,
            'tiers' => $tiers_js,
            'dates' => $available_dates,
            'currency' => get_woocommerce_currency_symbol(),
            'is_livestream' => $is_livestream,
            'select_days_text' => __('Select days', 'alttag-registrations'),
            'total_label' => __('Total', 'alttag-registrations'),
            'days_label' => __('days', 'alttag-registrations'),
        ];
    }

    /**
     * Generate variable symbol prefix from product SKU
     */
    public function variableSymbolPrefix($prefix)
    {
        return ctx()->variableSymbolPrefix($prefix);
    }
}
