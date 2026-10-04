<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

class StripeManager
{
    /**
     * True while the Stripe receipt (účtenka) is being rendered.
     *
     * The receipt reuses the e-mail footer text through
     * RegistrationContext::receiptData(), so a listener on
     * `alttag_registrations_footer_info_text` cannot otherwise tell a WC e-mail
     * apart from the receipt. Listeners that must not appear on the receipt
     * check this flag.
     */
    public static $rendering_receipt = false;

    /**
     * Order meta flag: the attached Stripe customer's e-mail has already been
     * synced to the order's billing e-mail. The intent args filter runs for
     * both intent creation and update, so the API call must happen once only.
     */
    private const CUSTOMER_EMAIL_SYNCED = '_alttag_stripe_customer_email_synced';

    private $stripe_gateway;

    public function registerHooks()
    {
        add_action('woocommerce_init', [$this, 'initStripeGateway']);

        // Fire the Stripe receipt on `woocommerce_payment_complete` - that
        // action runs from `WC_Order::payment_complete()` AFTER the gateway
        // has attached `_payment_intent_id` / charge to the order. Firing
        // the receipt earlier (from the inline sendCustomEmail path in
        // WooCommerceManager) races with the woo-stripe-payment plugin's
        // capture step: the `_status_changed(pending, processing)` transition
        // reaches our sendCustomEmail BEFORE Stripe finishes capturing the
        // charge and writing the intent id, so `getReceiptHtmlFromOrder()`
        // returns null and we silently skip. `payment_complete` fires exactly
        // once per successful payment - no idempotency flag needed.
        add_action('woocommerce_payment_complete', [$this, 'sendReceiptOnPaymentComplete'], 100);

        // Attach product / event metadata to Stripe PaymentIntents / Charges
        // so transactions can be filtered by product in the Stripe dashboard.
        //
        // New plugin (woo-stripe-payment, gateway id `stripe_cc`)
        add_filter('wc_stripe_payment_intent_args', [$this, 'injectProductMetadataIntoIntentArgs'], 20, 3);
        // Same filter: stamp the checkout billing e-mail onto the payment so
        // the Stripe dashboard does not show the WP account e-mail instead.
        add_filter('wc_stripe_payment_intent_args', [$this, 'syncBillingEmailIntoIntentArgs'], 20, 2);
        // Old plugin (woocommerce-gateway-stripe) - UPE / PaymentIntent flow
        add_filter('wc_stripe_intent_metadata', [$this, 'injectProductMetadata'], 20, 2);
        // Old plugin - legacy Charge flow
        add_filter('wc_stripe_payment_metadata', [$this, 'injectProductMetadata'], 20, 2);

        // Apple Pay / Google Pay / Payment Request express buttons open the
        // wallet sheet without running the WC checkout form - registration
        // custom fields, GDPR consent and day selection are never collected.
        // Gate the click in JS so the wallet only opens when the form would
        // pass HTML5 required-field validation.
        add_action('wp_enqueue_scripts', [$this, 'enqueueExpressCheckoutGuard']);
    }

    public function enqueueExpressCheckoutGuard()
    {
        // Wallet buttons render wherever Stripe's express checkout is enabled;
        // live runs them on product + cart (see express_checkout_button_locations),
        // so the guard must load there too - not only on the checkout page.
        $is_wallet_page = false;
        if (function_exists('is_checkout')) {
            $is_wallet_page = is_checkout();
        }
        if (!$is_wallet_page && function_exists('is_product')) {
            $is_wallet_page = is_product();
        }
        if (!$is_wallet_page && function_exists('is_cart')) {
            $is_wallet_page = is_cart();
        }
        if (!$is_wallet_page) {
            return;
        }
        $path = ALTTAG_REGISTRATIONS_PATH . '/assets/js/express-checkout-guard.js';
        if (!file_exists($path)) {
            return;
        }
        wp_enqueue_script(
            'alttag-registrations-express-checkout-guard',
            ALTTAG_REGISTRATIONS_URL . 'assets/js/express-checkout-guard.js',
            ['jquery'],
            filemtime($path),
            true
        );
        // When the buyer ticks "Buy as Business client" (SF plugin's
        // `#wi_as_company` toggle), the company block becomes required -
        // but the individual fields carry no `required` attribute and no
        // `.validate-required` wrapper (SF enforces the requirement server-
        // side via option values, not DOM). Without an explicit list here,
        // the express-checkout guard sees the form as valid and lets Google
        // Pay / Apple Pay through with an empty Tax ID / Company ID.
        //
        // Filter `alttag_registrations_business_required_field_ids` lets
        // downstream projects override the DOM ID list (e.g. custom
        // gateways, non-SF invoicing).
        $business_field_ids = apply_filters(
            'alttag_registrations_business_required_field_ids',
            [
                'billing_company',
                'billing_company_wi_id',
                'billing_company_wi_tax',
            ]
        );

        wp_localize_script(
            'alttag-registrations-express-checkout-guard',
            'alttagExpressGuard',
            [
                'noticeText' => __(
                    'Please fill in all required checkout fields first.',
                    'alttag-registrations'
                ),
                'businessToggleId'     => 'wi_as_company',
                'businessRequiredFields' => array_values($business_field_ids),
            ]
        );
    }

    // =========================================================================
    // Product metadata → Stripe
    // =========================================================================

    /**
     * Build product-level metadata for the given order.
     * Filterable via `alttag_registrations_stripe_metadata`.
     *
     * Stripe limits: max 50 keys, 40-char keys, 500-char values.
     *
     * @param \WC_Order $order
     * @return array<string,string>
     */
    public function buildStripeProductMetadata(\WC_Order $order): array
    {
        $items = $order->get_items();
        if (empty($items)) {
            return [];
        }

        $first = reset($items);
        $product_id = (int) ($first->get_variation_id() ?: $first->get_product_id());
        $product = $product_id ? wc_get_product($product_id) : null;
        if (!$product) {
            return [];
        }

        $truncate = static function ($v, int $max = 500): string {
            $v = is_scalar($v) ? (string) $v : '';
            return mb_substr($v, 0, $max);
        };

        $meta = [
            'product_id' => (string) $product_id,
            'product_name' => $truncate($product->get_name()),
            'product_sku' => $truncate($product->get_sku()),
            'quantity' => (string) $first->get_quantity(),
        ];

        // Event-specific product meta (keys from EventManager)
        $event_meta_map = [
            '_event_name' => 'event_name',
            '_event_date_start' => 'event_date_start',
            '_event_date_end' => 'event_date_end',
            '_event_venue' => 'event_venue',
            '_variable_symbol_prefix' => 'variable_symbol_prefix',
        ];
        foreach ($event_meta_map as $post_meta => $stripe_key) {
            $v = get_post_meta($product_id, $post_meta, true);
            if ($v !== '' && $v !== null && $v !== false) {
                $meta[$stripe_key] = $truncate($v);
            }
        }

        // Language (Polylang)
        if (function_exists('pll_get_post_language')) {
            $lang = pll_get_post_language($product_id);
            if ($lang) {
                $meta['language'] = (string) $lang;
            }
        }

        // Livestream vs in-person
        try {
            $meta['is_livestream'] = ctx()->withOrder($order)->isLivestreamEmailTarget() ? 'yes' : 'no';
        } catch (\Throwable $e) {
            // ctx may not be available during very early boot - ignore
        }

        // Participant types breakdown (e.g. "adult:2,child:1")
        if (function_exists('WC') && WC()->session) {
            $types_data = WC()->session->get('selected_participant_types_data', []);
            if (is_array($types_data) && !empty($types_data)) {
                $parts = [];
                foreach ($types_data as $id => $count) {
                    $c = (int) $count;
                    if ($c > 0) {
                        $parts[] = sanitize_key((string) $id) . ':' . $c;
                    }
                }
                if (!empty($parts)) {
                    $meta['participant_types'] = $truncate(implode(',', $parts));
                }
            }
        }

        // Selected days (e.g. "2026-04-10,2026-04-11")
        $selected_days = $order->get_meta('selected_days');
        if (is_array($selected_days) && !empty($selected_days)) {
            $meta['selected_days'] = $truncate(implode(',', $selected_days));
        }

        /**
         * Filter to customize Stripe metadata attached to the PaymentIntent.
         *
         * @param array      $meta   Metadata key/value pairs to send to Stripe.
         * @param \WC_Order  $order  WooCommerce order.
         */
        return apply_filters('alttag_registrations_stripe_metadata', $meta, $order);
    }

    /**
     * Hook handler for the new plugin's wc_stripe_payment_intent_args filter.
     *
     * @param array       $args     Full PaymentIntent args.
     * @param \WC_Order   $order
     * @param mixed       $payment  Payment method object (unused).
     * @return array
     */
    public function injectProductMetadataIntoIntentArgs($args, $order, $payment = null)
    {
        if (!($order instanceof \WC_Order)) {
            return $args;
        }
        if (!is_array($args)) {
            $args = [];
        }
        $existing = isset($args['metadata']) && is_array($args['metadata']) ? $args['metadata'] : [];
        $args['metadata'] = array_merge($existing, $this->buildStripeProductMetadata($order));
        return $args;
    }

    /**
     * Record the checkout billing e-mail on the Stripe payment.
     *
     * When a logged-in WP user pays, woo-stripe-payment attaches their Stripe
     * Customer object to the PaymentIntent, and that customer was created from
     * the WP account e-mail. The dashboard's e-mail column reads
     * `customer.email`, so the payment shows the account e-mail instead of the
     * address typed into checkout, which is what the shop reconciles by.
     *
     * Sets searchable `billing_email` metadata and - when the attached
     * customer's address differs - syncs the Stripe customer's e-mail before
     * the intent is created. The dashboard's e-mail column reads
     * `customer.email`, so the sync is what fixes it.
     *
     * `receipt_email` is deliberately NOT set: the plugin sends its own
     * branded receipt e-mail, and setting it makes Stripe mail a second,
     * unbranded English receipt (with the account-wide support URL and
     * contact address in the footer) on top of it.
     *
     * No-op unless the new plugin is active: only it fires this filter and
     * provides `wc_stripe_get_container()`.
     *
     * @param array     $args  Full PaymentIntent args.
     * @param \WC_Order $order
     * @return array
     */
    public function syncBillingEmailIntoIntentArgs($args, $order)
    {
        if (!($order instanceof \WC_Order) || !is_array($args)) {
            return $args;
        }

        $email = $order->get_billing_email();
        if ($email === '' || !is_email($email)) {
            return $args;
        }

        // Searchable metadata always carries the checkout e-mail.
        $existing = isset($args['metadata']) && is_array($args['metadata']) ? $args['metadata'] : [];
        $existing['billing_email'] = $email;
        $args['metadata'] = $existing;

        $this->syncStripeCustomerEmail($args, $order, $email);

        return $args;
    }

    /**
     * Update the attached Stripe customer's e-mail to the order billing e-mail.
     *
     * Uses the customer id the intent args already carry, never re-looks it up.
     *
     * @param array     $args
     * @param \WC_Order $order
     * @param string    $email Validated billing e-mail.
     * @return void
     */
    private function syncStripeCustomerEmail(array $args, \WC_Order $order, string $email)
    {
        // Without an attached customer the dashboard falls back to the billing
        // details / receipt e-mail, nothing else to do (guest checkout).
        if (empty($args['customer'])) {
            return;
        }

        // Sync once per order; this filter runs on intent create and update.
        if ($order->get_meta(self::CUSTOMER_EMAIL_SYNCED)) {
            return;
        }

        // Same address on the WP account and the order: already consistent.
        $user = $order->get_customer_id() ? get_userdata($order->get_customer_id()) : false;
        if ($user && 0 === strcasecmp($user->user_email, $email)) {
            return;
        }

        // Old Stripe plugin (or no Stripe plugin) - the container does not exist.
        if (!function_exists('wc_stripe_get_container') || !class_exists('\WC_Stripe_Constants')) {
            return;
        }

        try {
            // The woo-stripe-payment SDK wrapper returns WP_Error on API
            // failure instead of throwing.
            $result = wc_stripe_get_container()
                ->get(\PaymentPlugins\Stripe\Client\StripeClient::class)
                ->mode($order->get_meta(\WC_Stripe_Constants::MODE))
                ->customers->update($args['customer'], ['email' => $email]);

            if (is_wp_error($result)) {
                $this->logStripeCustomerEmailFailure($result->get_error_message());
                return;
            }

            $order->update_meta_data(self::CUSTOMER_EMAIL_SYNCED, 1);
            $order->save();
        } catch (\Throwable $e) {
            // Payment must proceed even if the sync fails; receipt_email and
            // the metadata above still identify the buyer.
            $this->logStripeCustomerEmailFailure($e->getMessage());
        }
    }

    /**
     * @param string $message
     * @return void
     */
    private function logStripeCustomerEmailFailure(string $message)
    {
        if (function_exists('wc_stripe_log_error')) {
            wc_stripe_log_error('alttag-registrations: Stripe customer e-mail sync failed: ' . $message);
        }
    }

    /**
     * Hook handler for wc_stripe_intent_metadata and wc_stripe_payment_metadata.
     *
     * @param array     $metadata
     * @param \WC_Order $order
     * @return array
     */
    public function injectProductMetadata($metadata, $order)
    {
        if (!($order instanceof \WC_Order)) {
            return $metadata;
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }
        return array_merge($metadata, $this->buildStripeProductMetadata($order));
    }

    /**
     * Initialize Stripe Gateway after WooCommerce is fully loaded.
     * Supports both old (woocommerce-gateway-stripe) and new (woo-stripe-payment) plugins.
     */
    public function initStripeGateway()
    {
        if (!function_exists('WC') || !WC()->payment_gateways) {
            return;
        }

        $payment_gateways = WC()->payment_gateways->payment_gateways();

        // New plugin: woo-stripe-payment (gateway ID: stripe_cc)
        if (isset($payment_gateways['stripe_cc'])) {
            $this->stripe_gateway = $payment_gateways['stripe_cc'];
            return;
        }

        // Old plugin: woocommerce-gateway-stripe (gateway ID: stripe)
        if (isset($payment_gateways['stripe'])) {
            $this->stripe_gateway = $payment_gateways['stripe'];
        }
    }

    public function init()
    {
        add_action('wp', function () {
            if (!empty($_GET['test_stripe_receipt'])) {
                $order_id = (int) $_GET['test_stripe_receipt'];
                $order = wc_get_order($order_id);
                $receipt_html = $order instanceof \WC_Order ? $this->getReceiptHtmlFromOrder($order) : '';
                // Load xpath analyzer
                echo $receipt_html;
                die();
            }

            if (!empty($_GET['test_stripe_receipt_in_anaylzer'])) {
                $order_id = (int) $_GET['test_stripe_receipt_in_anaylzer'];
                $order = wc_get_order($order_id);
                $receipt_html = $order instanceof \WC_Order ? $this->getReceiptHtmlFromOrder($order, false) : '';
                // Load xpath analyzer
                ob_start();
                include(ALTTAG_REGISTRATIONS_PATH . '/templates/xpath-analyzer.php');
                $receipt_html = ob_get_clean();

                echo $receipt_html;
                die();
            }
        });
    }

    /**
     * Get receipt URL from order's payment intent
     *
     * @param \WC_Order $order WooCommerce order object
     * @return string|null Receipt URL or null if not found
     */
    public function getReceiptHtmlFromOrder(\WC_Order $order, $translate = true)
    {
        if (!$this->stripe_gateway) {
            return null;
        }

        try {
            // New plugin (woo-stripe-payment) uses _payment_intent_id
            // Old plugin (woocommerce-gateway-stripe) uses _stripe_intent_id
            $payment_intent_id = $order->get_meta('_payment_intent_id');
            if (empty($payment_intent_id)) {
                $payment_intent_id = $order->get_meta('_stripe_intent_id');
            }
            if (empty($payment_intent_id)) {
                return null;
            }

            $charge = $this->getLatestCharge($payment_intent_id);
            if (!$charge || empty($charge->receipt_url)) {
                return null;
            }

            return $this->getTranslatedReceiptHtml($charge->receipt_url, $charge, $translate, $order);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the latest charge from a payment intent.
     * Supports both old (woocommerce-gateway-stripe) and new (woo-stripe-payment) plugins.
     *
     * Stripe API 2022+ expanded PaymentIntent responses to prefer
     * `latest_charge` (a charge ID string) over the older `charges->data[]`
     * array. woo-stripe-payment's `fetch_payment_intent()` retrieves the
     * intent without expanding charges, so we get `latest_charge` populated
     * but `charges` empty/null. Handle both shapes plus a final fallback
     * of retrieving the charge by ID from the passed intent ID directly.
     */
    private function getLatestCharge($payment_intent_id)
    {
        // New plugin: woo-stripe-payment - uses WC_Stripe_Gateway singleton
        if (class_exists('WC_Stripe_Gateway')) {
            $intent = \WC_Stripe_Gateway::load()->fetch_payment_intent($payment_intent_id);
            if (is_wp_error($intent) || !$intent) {
                return null;
            }

            // Modern shape: latest_charge is a charge ID string; retrieve it.
            $charge_id = null;
            if (!empty($intent->latest_charge)) {
                $charge_id = is_object($intent->latest_charge)
                    ? ($intent->latest_charge->id ?? null)
                    : $intent->latest_charge;
            }
            if ($charge_id && method_exists(\WC_Stripe_Gateway::load(), 'get_charge')) {
                $charge = \WC_Stripe_Gateway::load()->get_charge($charge_id);
                if (!is_wp_error($charge) && $charge) {
                    return $charge;
                }
            }

            // Legacy shape: charges->data[0]
            if (!empty($intent->charges->data[0])) {
                return $intent->charges->data[0];
            }

            return null;
        }

        // Old plugin: woocommerce-gateway-stripe - uses WC_Stripe_API
        if (class_exists('WC_Stripe_API')) {
            $intent = \WC_Stripe_API::request([], "payment_intents/{$payment_intent_id}", 'GET');
            if (!empty($intent->error)) {
                return null;
            }
            return $this->stripe_gateway->get_latest_charge_from_intent($intent);
        }

        return null;
    }

    private function getTranslatedReceiptHtml($receipt_url, $charge, $translate = true, $order = null)
    {
        // Download receipt html file
        $receipt_html = file_get_contents($receipt_url);

        if (!$translate) {
            return $receipt_html;
        }

        $context = $order instanceof \WC_Order ? ctx()->withOrder($order) : ctx();

        // receiptData() runs the footer/info-text filters; flag the receipt so
        // e-mail-only additions can opt out. Restored in a finally so an
        // exception cannot leave the flag set for the rest of the request.
        self::$rendering_receipt = true;
        try {
            $localized_data = $context->receiptData();
        } finally {
            self::$rendering_receipt = false;
        }

        $created_timestamp = $charge->created;
        $created = date('Y-m-d H:i:s', $created_timestamp);
        $created = new \DateTime($created, new \DateTimeZone('UTC'));
        $created->setTimezone(new \DateTimeZone('Europe/Bratislava'));
        $created_date = $created->format('d. m. Y H:i:s');

        // Load it as html and override there values by selectors
        $dom = new \DOMDocument();
        @$dom->loadHTML($receipt_html);
        $xpath = new \DOMXPath($dom);

        // Check if the receipt contains coupon code by search "off)" string
        $has_coupon = strpos($receipt_html, 'off)') !== false;

        // Check if the receipt contains vat by search "DPH" string
        $has_vat = strpos($receipt_html, 'VAT') !== false;

        // Check if the receipt contains subtotal by searching for the "Subtotal" string
        $has_subtotal = strpos($receipt_html, 'Subtotal') !== false;

        // Helper function to safely update node value and log errors
        $updateNodeValue = function ($query, $value) use ($xpath, $dom) {
            $nodes = $xpath->query($query);
            if ($nodes->length > 0) {
                $nodes->item(0)->nodeValue = $value;
            }
        };

        // Receipt title
        $receipt_title_node = $xpath->query('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table/tbody/tr/td/span')->item(0);

        if ($receipt_title_node) {
            $title_parts = explode(' ', $receipt_title_node->nodeValue);

            // Find amount and format it
            $amount_index = array_search('Amount', $title_parts);
            if ($amount_index !== false && isset($title_parts[$amount_index + 2])) {
                $amount = $title_parts[$amount_index + 2];
                $formatted_amount = $this->parsePrice($amount);
                $title_parts[$amount_index + 2] = $formatted_amount;
            }

            // Find date and format it
            $date_index = array_search('Date', $title_parts);
            if ($date_index !== false && isset($title_parts[$date_index + 2])) {
                // Get the date parts
                $date_str = implode(' ', array_slice($title_parts, $date_index + 2, 4));
                $date = new \DateTime($date_str);
                $formatted_date = $date->format('d. m. Y, H:i:s');

                // Replace the old date parts with the new format
                array_splice($title_parts, $date_index + 2, 4, [$formatted_date]);
            }

            // Reconstruct the title
            $title_text = implode(' ', $title_parts);
            $title_text = apply_filters('alttag_registrations_receipt_heading', $title_text, $order);
            $receipt_title_node->nodeValue = $title_text;
        }

        // Receipt from
        $updateNodeValue('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[2]/tbody/tr[1]/td', $localized_data['receipt_from']);

        // Receipt number
        $receipt_number_node = $xpath->query('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[3]/tbody/tr[1]/td')->item(0);
        if ($receipt_number_node) {
            $receipt_number_parts = explode(' ', trim($receipt_number_node->nodeValue));
            $receipt_number_parts[0] = __('Receipt', 'alttag-registrations');
            $receipt_number = $receipt_number_parts[1] ?? '';
            $receipt_number_implode = implode(' ', $receipt_number_parts);
            $receipt_number_node->nodeValue = $receipt_number_implode;
        }

        // Update other elements
        $updateNodeValue('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[5]/tbody/tr/td[3]/table/tbody/tr[1]/td', __('Paid amount', 'alttag-registrations'));
        $node = $xpath->query('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[5]/tbody/tr/td[3]/table/tbody/tr[2]/td')->item(0);
        $updateNodeValue('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[5]/tbody/tr/td[3]/table/tbody/tr[2]/td', $this->parsePrice($node ? $node->nodeValue : ''));
        $updateNodeValue('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[5]/tbody/tr/td[5]/table/tbody/tr[1]/td', __('Payment date', 'alttag-registrations'));
        $updateNodeValue('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[5]/tbody/tr/td[5]/table/tbody/tr[2]/td', $created_date);
        $updateNodeValue('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[5]/tbody/tr/td[7]/table/tbody/tr[1]/td', __('Payment method', 'alttag-registrations'));
        $updateNodeValue('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[7]/tbody/tr[2]/td[2]/span', __('Summary', 'alttag-registrations'));

        // Get summary table
        $summary_table = $xpath->query('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[8]')->item(0);

        if ($summary_table) {
            // Get the product title node
            $product_title_xpath = '/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[8]/tbody/tr[2]/td[2]/table/tbody/tr/td/table/tbody/tr[2]/td[2]/table/tr[2]/td[1]';
            $product_title_node = $xpath->query($product_title_xpath)->item(0);

            if ($product_title_node) {
                // Get the current title
                $title = $product_title_node->nodeValue;
                $title = trim($title);
                // Replace empty spaces with a single space
                $title = preg_replace('/\s+/', ' ', $title);
                // Remove " - Order XXX" from the end of the title using regex
                $title = preg_replace('/ - Objednávka \d+$/', '', $title);
                $title = preg_replace('/ - Order \d+$/', '', $title);

                $title = apply_filters('alttag_registrations_receipt_product_title', $title, $order);

                // Update the node with the cleaned title
                $updateNodeValue($product_title_xpath, $title);
            }

            // Get the product price price node
            $product_price_xpath = '/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[8]/tbody/tr[2]/td[2]/table/tbody/tr/td/table/tbody/tr[2]/td[2]/table/tr[2]/td[3]';
            $product_price_node = $xpath->query($product_price_xpath)->item(0);

            if ($product_price_node) {
                // Fix amount paid price value format
                $updateNodeValue($product_price_xpath, $this->parsePrice($product_price_node->nodeValue));
            }

            // Get the amount paid label node
            $amount_paid_label_xpath = '/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[8]/tbody/tr[2]/td[2]/table/tbody/tr/td/table/tbody/tr[2]/td[2]/table/tr[7]/td[1]/strong';
            $amount_paid_label_node = $xpath->query($amount_paid_label_xpath)->item(0);

            if ($amount_paid_label_node) {
                $amount_paid_label_node->nodeValue = __('Amount paid', 'alttag-registrations');
            }

            // Get the amount paid price node
            $amount_paid_price_xpath = '/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[8]/tbody/tr[2]/td[2]/table/tbody/tr/td/table/tbody/tr[2]/td[2]/table/tr[7]/td[3]/strong';
            $amount_paid_price_node = $xpath->query($amount_paid_price_xpath)->item(0);

            if ($amount_paid_price_node) {
                // Fix amount paid price value format
                $updateNodeValue($amount_paid_price_xpath, $this->parsePrice($amount_paid_price_node->nodeValue));
            }

            $coupon_row = 9;
            $vat_row = 9;
            $total_amount_row = 9;

            if ($has_vat) {
                $total_amount_row += 2;
            }

            if ($has_coupon) {
                $total_amount_row += 2;
                $vat_row += 2;
            }

            if ($has_coupon) {
                $coupon_label_node = $xpath->query('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[8]/tbody/tr[2]/td[2]/table/tbody/tr/td/table/tbody/tr[2]/td/table/tr['.$coupon_row.']/td[1]')->item(0);
                if ($coupon_label_node) {
                    $coupon_label = str_replace('off', __('off', 'alttag-registrations'), $coupon_label_node->nodeValue);
                    $coupon_label_node->nodeValue = $coupon_label;
                }

                $coupon_amount_node = $xpath->query('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[8]/tbody/tr[2]/td[2]/table/tbody/tr/td/table/tbody/tr[2]/td/table/tr['.$coupon_row.']/td[3]')->item(0);
                if ($coupon_amount_node) {
                    $updateNodeValue('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[8]/tbody/tr[2]/td[2]/table/tbody/tr/td/table/tbody/tr[2]/td/table/tr['.$coupon_row.']/td[3]', $this->parsePrice($coupon_amount_node->nodeValue));
                }
            }

            if ($has_vat) {
                $vat_label_node = $xpath->query('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[8]/tbody/tr[2]/td[2]/table/tbody/tr/td/table/tbody/tr[2]/td/table/tr['.$vat_row.']/td[1]')->item(0);
                if ($vat_label_node) {
                    $vat_label = str_replace('VAT', __('VAT', 'alttag-registrations'), $vat_label_node->nodeValue);
                    $vat_label = str_replace('Slovakia', __('Slovakia', 'alttag-registrations'), $vat_label);
                    $vat_label_node->nodeValue = $vat_label;
                }

                $vat_amount_node = $xpath->query('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[8]/tbody/tr[2]/td[2]/table/tbody/tr/td/table/tbody/tr[2]/td/table/tr['.$vat_row.']/td[3]')->item(0);
                if ($vat_amount_node) {
                    $updateNodeValue('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[8]/tbody/tr[2]/td[2]/table/tbody/tr/td/table/tbody/tr[2]/td/table/tr['.$vat_row.']/td[3]', $this->parsePrice($vat_amount_node->nodeValue));
                }
            }

            if ($has_subtotal) {
                $subtotal_label_node = $xpath->query('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[8]/tbody/tr[2]/td[2]/table/tbody/tr/td/table/tbody/tr[2]/td/table/tr[7]/td[1]')->item(0);
                if ($subtotal_label_node) {
                    $subtotal_label = str_replace('Subtotal', __('Subtotal', 'alttag-registrations'), $subtotal_label_node->nodeValue);
                    $subtotal_label_node->nodeValue = $subtotal_label;
                }

                $subtotal_amount_node = $xpath->query('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[8]/tbody/tr[2]/td[2]/table/tbody/tr/td/table/tbody/tr[2]/td/table/tr[7]/td[3]')->item(0);
                if ($subtotal_amount_node) {
                    $updateNodeValue('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[8]/tbody/tr[2]/td[2]/table/tbody/tr/td/table/tbody/tr[2]/td/table/tr[7]/td[3]', $this->parsePrice($subtotal_amount_node->nodeValue));
                }
            }

            $updateNodeValue('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[8]/tbody/tr[2]/td[2]/table/tbody/tr/td/table/tbody/tr[2]/td/table/tr['.$total_amount_row.']/td[1]/strong', __('Total amount', 'alttag-registrations'));

            $total_amount_node = $xpath->query('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[8]/tbody/tr[2]/td[2]/table/tbody/tr/td/table/tbody/tr[2]/td/table/tr['.$total_amount_row.']/td[3]/strong')->item(0);
            if ($total_amount_node) {
                $updateNodeValue('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[8]/tbody/tr[2]/td[2]/table/tbody/tr/td/table/tbody/tr[2]/td/table/tr['.$total_amount_row.']/td[3]/strong', $this->parsePrice($total_amount_node->nodeValue));
            }
        }

        // Info text
        $info_text_node = $xpath->query('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[10]/tbody/tr[2]/td[2]')->item(0);
        if ($info_text_node) {
            $is_livestream_user = $order instanceof \WC_Order
                ? $context->isLivestreamParticipant()
                : false;

            // Allow filtering info text visibility by order
            $show_info_text = apply_filters('alttag_registrations_receipt_show_info_text', !$is_livestream_user, $order);

            if ($show_info_text) {
                $info_text_node->textContent = '';
                $fragment = $dom->createDocumentFragment();
                // Guard against empty / missing info_text - appendXML on an
                // empty string returns false and appendChild() then complains
                // with "Document Fragment is empty" warnings in debug.log.
                $info_text = isset($localized_data['info_text']) ? (string) $localized_data['info_text'] : '';
                if ($info_text !== '' && @$fragment->appendXML($info_text) && $fragment->hasChildNodes()) {
                    $info_text_node->appendChild($fragment);
                }
            } else {
                // Remove info text node
                $info_text_node->parentNode->removeChild($info_text_node);
            }
        }

        $footer_text_node = $xpath->query('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[12]/tbody/tr/td[2]')->item(0);
        if ($footer_text_node) {
            $footer_text_node->textContent = '';

            $fragment = $dom->createDocumentFragment();
            $fragment->appendXML('<p style="font-size: 16px; line-height: 1.4;">'.sprintf($localized_data['footer_info_text'], $localized_data['event_name_with_date']).'</p>');
            $footer_text_node->appendChild($fragment);
        }

        // Remove node footer info
        $footer_info_node = $xpath->query('/html/body/table/tbody/tr[2]/td/table/tbody/tr/td/table[14]')->item(0);
        if ($footer_info_node) {
            $footer_info_node->parentNode->removeChild($footer_info_node);
        }

        $receipt_html = $dom->saveHTML();

        return $receipt_html;
    }

    private function parsePrice($input_string)
    {
        $input_string = trim($input_string);

        // Define a regular expression pattern to match currency symbols and amounts (including negative values)
        $pattern = '/^(-?)([\p{Sc}])([\d.,]+)$/u';

        // Perform the regular expression match
        preg_match($pattern, $input_string, $matches);

        if (count($matches) === 4) {
            $sign = $matches[1];
            $currency_symbol = $matches[2];
            $amount = $matches[3];

            // Remove any commas from the amount
            $amount = str_replace(',', '', $amount);

            // Format the amount with two decimal places
            $formatted_amount = number_format((float)$amount, 2, '.', '');

            // Construct the formatted price string
            $formatted_price = $sign . $formatted_amount . '&nbsp;' . $currency_symbol;

            return $formatted_price;
        }

        return $input_string;
    }

    /**
     * Dispatch the buyer's Stripe receipt when WC signals payment complete.
     *
     * `woocommerce_payment_complete` runs from `WC_Order::payment_complete()`
     * AFTER the gateway has confirmed the payment and set the intent/charge
     * meta on the order. That's the earliest reliable point at which
     * `getReceiptHtmlFromOrder()` can find `_payment_intent_id`.
     */
    public function sendReceiptOnPaymentComplete($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }
        $this->submitReceipt([
            'email'    => $order->get_billing_email(),
            'order_id' => (int) $order_id,
        ]);
    }

    public function submitReceipt($order_data = [])
    {
        $to = $order_data['email'] ?? '';
        if (!$to) {
            return false;
        }

        $order_id = (int) $order_data['order_id'] ?? null;
        if (!$order_id) {
            return false;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return false;
        }

        if (!str_starts_with($order->get_payment_method(), 'stripe')) {
            return false;
        }

        // Ensure the Stripe gateway singleton is populated. `initStripeGateway`
        // is bound to `woocommerce_init` but can be missed in payment-webhook
        // or admin-post contexts that fire before our callback runs. Without
        // the gateway, `getReceiptHtmlFromOrder()` returns null.
        if (!$this->stripe_gateway) {
            $this->initStripeGateway();
        }

        do_action('alttag_registrations_before_receipt_email', $order);

        $receipt_html = $this->getReceiptHtmlFromOrder($order);
        if (!$receipt_html) {
            do_action('alttag_registrations_after_receipt_email', $order);
            return false;
        }

        $subject = get_receipt_subject($order);
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $mail_sent = wp_mail($to, $subject, $receipt_html, $headers);

        do_action('alttag_registrations_after_receipt_email', $order);

        if (!$mail_sent) {
            return false;
        }

        return true;
    }
}
