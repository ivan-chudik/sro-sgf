<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

class WooCommerceManager
{
    private $participantManager;
    private $settings;
    private $stripeManager;

    public function setParticipantManager($participantManager)
    {
        $this->participantManager = $participantManager;
    }

    public function setSettings($settings)
    {
        $this->settings = $settings;
    }

    public function setStripeManager($stripeManager)
    {
        $this->stripeManager = $stripeManager;
    }


    public function init()
    {
        // Disable default WooCommerce emails
        add_filter('woocommerce_email_enabled_new_order', '__return_false');
        add_filter('woocommerce_email_enabled_cancelled_order', '__return_false');
        add_filter('woocommerce_email_enabled_failed_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_failed_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_cancelled_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_partially_refunded_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_on_hold_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_processing_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_completed_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_refunded_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_invoice', '__return_false');

        // Update participant when order is updated
        add_action('woocommerce_order_status_changed', [$this, 'handleOrderStatusChange'], 10, 3);

        // Save custom fields
        add_action('woocommerce_process_shop_order_meta', [$this, 'saveCustomOrderFields'], 10, 2);

        // Override WooCommerce thank you template
        add_filter('wc_get_template', [$this, 'overrideWooCommerceTemplates'], 10, 5);
        add_filter('woocommerce_thankyou_order_received_text', '__return_empty_string');

        // Set data to invoice
        add_filter('sf_invoice_data', [$this, 'addInvoiceData'], 10, 3);
        add_filter('sf_item_data', [$this, 'cleanInvoiceItem'], 10, 4);
        add_filter('sf_client_data', [$this, 'addClientData'], 10, 3);

        // Zero total order handling - skip invoice creation
        add_filter('sf_skip_invoice', [$this, 'skipInvoiceForZeroTotalOrders'], 10, 2);

        // Strip the trailing "(N EUR)" amount suffix from SuperFaktura
        // discount line names — invoices read more cleanly with just "Zľava"
        // instead of "Zľava (0 EUR)".
        add_filter('sf_discount_data', [$this, 'cleanDiscountData'], 10, 2);

        // Make virtual products skip order processing status
        add_filter('woocommerce_order_item_needs_processing', [$this, 'wcOrderItemNeedsProcessing'], 10, 3);

        // Localize tax labels (e.g. VAT → DPH for Slovak)
        add_filter('woocommerce_rate_label', [$this, 'localizeTaxLabel']);

        // Append the rate percentage to each cart-tax-totals label so the
        // breakdown shows e.g. "DPH 5%" instead of just "DPH"
        add_filter('woocommerce_cart_tax_totals', [$this, 'appendRateToTaxLabel'], 10, 2);

        // Disable product and archive pages
        add_action('template_redirect', [$this, 'disableProductAndArchivePages']);

        // Handle add to cart from URL and redirect to checkout
        add_action('wp_loaded', [$this, 'handleAddToCartFromURL']);

        // Sync cart product type flags to session
        add_action('alttag_registrations_add_to_cart', [$this, 'syncCartFlagsOnAdd'], 10, 3);
        add_action('wp_loaded', [$this, 'syncCartFlags']);

        // Add custom checkout fields
        add_filter('woocommerce_checkout_fields', [$this, 'modifyCheckoutFields']);

        // Set SF field labels/priorities at billing fields level (runs after SF at p:10)
        add_filter('woocommerce_billing_fields', [$this, 'modifyBillingFieldLabels'], 20);

        // Override Elementor Pro field labels at render time (runs after Elementor's p:70)
        add_filter('woocommerce_form_field_args', [$this, 'overrideFieldLabelsAtRender'], 80, 2);

        // Restore Elementor Pro coupon section on first checkout load (see method docblock)
        add_filter('woocommerce_cart_needs_payment', [$this, 'forceCouponSectionOnFirstLoad'], 9999, 2);

        // Add GDPR consent field after terms and conditions
        add_action('woocommerce_checkout_after_terms_and_conditions', [$this, 'addGDPRConsentField']);

        // Validate GDPR consent
        add_action('woocommerce_checkout_process', [$this, 'validateGDPRConsent']);

        // Add Terms and Conditions consent field
        add_action('woocommerce_checkout_after_terms_and_conditions', [$this, 'addTermsConsentField']);

        // Validate Terms and Conditions consent
        add_action('woocommerce_checkout_process', [$this, 'validateTermsConsent']);

        // Validate livestream email duplicates
        add_action('woocommerce_checkout_process', [$this, 'validateLivestreamEmailDuplicates']);

        // Validate SuperFaktura business-invoicing fields when the buyer
        // toggles "Buy as Business client". SF plugin ships its own
        // validation on the same hook, but some checkout implementations
        // (Elementor Pro's Woo Checkout widget, block checkout, custom
        // submitters) bypass SF's action registration; this is the safety
        // net that always fires from core.
        add_action('woocommerce_checkout_process', [$this, 'validateBusinessBillingFields']);
        // Bracket the SuperFaktúra validator, which runs on this action at the
        // default priority. See suspendSfCompanyRequirements().
        add_action('woocommerce_checkout_process', [$this, 'suspendSfCompanyRequirements'], 9);
        add_action('woocommerce_checkout_process', [$this, 'restoreSfCompanyRequirements'], 11);
        add_action('wp_enqueue_scripts', [$this, 'enqueueCheckoutErrorStyle']);

        // Save GDPR consent to order
        add_action('woocommerce_checkout_update_order_meta', [$this, 'saveGDPRConsent']);

        // Save language to order (for Polylang)
        add_action('woocommerce_checkout_create_order', [$this, 'saveLanguageToOrder'], 10, 2);

        // Set SuperFaktura invoice language based on order language (for Polylang)
        add_filter('sf_invoice_language', [$this, 'setSuperfakturaInvoiceLanguage'], 10, 3);

        // Save company data to session
        add_action('plugins_loaded', [$this, 'saveDataRequiredForElementorCheckoutFormToSession']);
        add_action('woocommerce_checkout_update_order_review', [$this, 'saveSuperfakturaFieldsToSession']);

        // Force company field to be optional
        add_filter('pre_option_woocommerce_checkout_company_field', [$this, 'forceCompanyFieldToBeOptional'], 999);

        // Force address 2 field to be hidden
        add_filter('pre_option_woocommerce_checkout_address_2_field', [$this, 'forceAddress2FieldToBeHidden'], 999);

        // Add test to resend email if get variable with order id
        add_action('init', [$this, 'handleResendEmail']);

        // Add dataLayer push for purchase tracking
        add_action('woocommerce_before_thankyou', [$this, 'pushPurchaseToDataLayer'], 10, 1);

        // Handle SuperFaktura payment callback
        add_action('wp_loaded', [$this, 'handleSuperfakturaPaymentCallback']);
    }

    /**
     * Handle order status change
     *
     * @param int $order_id
     * @param string $old_status
     * @param string $new_status
     */
    public function handleOrderStatusChange($order_id, $old_status, $new_status)
    {
        // Archive and trash are non-destructive lifecycle states, not fulfilment
        // transitions - re-syncing the participant on either state (a) creates
        // duplicates when the matching participant is no longer found via
        // the publish-only lookup and (b) sends no email or downstream
        // side-effect anyway. Skip the whole handler on these lifecycle moves.
        if ($new_status === 'archived' || $old_status === 'archived' ||
            $new_status === 'trash' || $old_status === 'trash') {
            return;
        }

        $order = wc_get_order($order_id);

        // One participant per registration line item. A mixed cart (e.g. a
        // livestream product and an in-person product, each with their own
        // selected days) must not collapse onto the first item's participant:
        // the second item would otherwise get no participant post, no ticket
        // and no livestream grant. Single-item orders loop exactly once, so
        // their behaviour is byte-for-byte what it was before.
        $items_data = [];
        foreach ($this->getRegistrationItems($order) as $item) {
            $item_data = $this->getOrderData($order, $item);
            $this->createOrUpdateParticipant($item_data);

            // Anchor this item's e-mail to its OWN participant. Without the
            // explicit id, buildOrderEmail() falls back to the order's first
            // participant and every item would carry the first ticket / QR.
            $participant = $this->findParticipantForItem($item_data);
            if (isset($participant->ID)) {
                $item_data['participant_id'] = (int) $participant->ID;
            }
            $items_data[] = $item_data;
        }
        if (!$items_data) {
            $items_data[] = $this->getOrderData($order);
        }

        // Send email only when status changes to processing or completed for the first time
        if ($new_status === 'processing' || $new_status === 'completed') {
            $this->sendConfirmationEmails($order_id, $items_data);
        }

        // Retry livestream access when order becomes completed/processing
        $this->retryLivestreamAccessOnStatusChange($order_id, $old_status, $new_status);
    }

    /**
     * Retry livestream access when order status changes to completed/processing
     *
     * @param int $order_id
     * @param string $old_status
     * @param string $new_status
     */
    private function retryLivestreamAccessOnStatusChange($order_id, $old_status, $new_status)
    {
        if ($new_status !== 'completed') {
            return;
        }

        if (!RegistrationContext::current()->isLivestreamEnabled()) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if (!\Alttag\Registrations\order_has_livestream_products($order)) {
            return;
        }

        // Retry per line item: in a mixed cart only the livestream item's
        // participant needs the grant, and it is not necessarily the first.
        foreach ($this->getRegistrationItems($order) as $item) {
            $order_data = $this->getOrderData($order, $item);
            $participant = $this->participantManager
                ->getParticipantByOrderItemId($order_id, (int) $item->get_id());
            if (!isset($participant->ID)) {
                $participant = $this->participantManager->getParticipantByOrderId($order_id);
            }
            if (!isset($participant->ID)) {
                continue;
            }

            $participant_id = $participant->ID;
            $state = ParticipantState::get($participant_id);
            $is_livestream_user = $state ? $state->isLivestream() : false;
            $current_access = $state ? $state->livestreamAccessStatus() : '';

            if ($is_livestream_user && $current_access !== LivestreamManager::GRANTED_STATE) {
                do_action('alttag_registrations_participant_update', $participant_id, $order_data);
            }
        }
    }

    /**
     * Save custom order fields
     *
     * @param int $order_id
     * @param WC_Order $order
     */
    public function saveCustomOrderFields($order_id, $order)
    {
        foreach ($this->getRegistrationItems($order) as $item) {
            $this->createOrUpdateParticipant($this->getOrderData($order, $item));
        }
    }

    /**
     * Registration line items of an order — the items that each get their own
     * participant. Add-on products (social evening, gala dinner …) never do;
     * if an order somehow contains nothing but add-ons, the first one is used
     * as a fallback so the rest of the pipeline still has something to anchor
     * to. Keyed by order item ID, in cart order.
     *
     * @param \WC_Order $order
     * @return array<int, \WC_Order_Item>
     */
    public function getRegistrationItems($order)
    {
        if (!$order instanceof \WC_Order) {
            return [];
        }

        $items = [];
        $fallback = [];
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            if (get_post_meta($product->get_id(), '_alttag_addon_product', true) === '1') {
                if (!$fallback) {
                    $fallback = [$item_id => $item];
                }
                continue;
            }
            $items[$item_id] = $item;
        }

        return $items ?: $fallback;
    }

    /**
     * Get order data
     *
     * @param WC_Order $order
     * @return array
     */
    public function getOrderData($order, $item = null)
    {
        if (!$order instanceof \WC_Order) {
            return [];
        }

        $business_id = $order->get_meta('_billing_company_wi_id') ?? '';
        // Note: SuperFaktura naming is confusing - wi_tax is DIČ (Tax ID), wi_vat is IČ DPH (VAT ID)
        $tax_id = $order->get_meta('_billing_company_wi_tax') ?? '';  // DIČ = Tax ID
        $vat_id = $order->get_meta('_billing_company_wi_vat') ?? '';  // IČ DPH = VAT ID

        // The *primary* line item — the registration product this order_data
        // (and therefore this participant/ticket/webhook) anchors to. Callers
        // that build one participant per line item pass $item explicitly;
        // with no $item we keep the historical behaviour of anchoring to the
        // order's first registration item.
        $registration_items = $this->getRegistrationItems($order);
        $item_ids = array_keys($registration_items);
        if ($item instanceof \WC_Order_Item) {
            $primary_item = $item;
            $item_index = array_search((int) $item->get_id(), $item_ids, true);
            $item_index = $item_index === false ? 0 : (int) $item_index;
        } else {
            $primary_item = $registration_items ? reset($registration_items) : null;
            $item_index = 0;
        }
        $order_data_item_index = $item_index;

        // Per-product variable symbol prefix takes priority over the
        // SuperFaktura invoice number.
        $variable_symbol = $order->get_meta('wc_sf_regular_invoice_number');
        if ($primary_item && $primary_item->get_product()) {
            $prefix = RegistrationContext::forProduct($primary_item->get_product()->get_id())
                ->variableSymbolPrefix('');
            if ($prefix !== '') {
                $variable_symbol = $prefix . $order->get_order_number();
            }
        }

        $order_data = [
            // Basic order data
            'order_id' => $order->get_id(),
            'order_status' => $order->get_status(),

            // Billing information
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'company_name' => $business_id ? $order->get_billing_company() : '', // Save company name only if required business_id is set
            'street' => $order->get_billing_address_1(),
            'city' => $order->get_billing_city(),
            'zip' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country(),

            // Company information
            'business_id' => $business_id,
            'vat_id' => $vat_id,
            'tax_id' => $tax_id,

            // Invoice information
            'variable_symbol' => $variable_symbol,
            'invoice_url' => $order->get_meta('wc_sf_invoice_regular'),
            'invoice_id' => $order->get_meta('wc_sf_internal_regular_id'),

            // Price (without tax)
            'price' => $order->get_total() - $order->get_total_tax(),

            // Payment method
            // Match any Stripe gateway (stripe, stripe_cc, stripe_googlepay,
            // stripe_applepay, …) used by both the official WC Stripe plugin
            // and Payment Plugins for Stripe (woo-stripe-payment).
            'payment_method' => str_starts_with((string) $order->get_payment_method(), 'stripe') ? 'card' :
                              ($order->get_payment_method() == 'invoice_payment' ? 'bank_transfer' : ''),

            // Order notes
            'order_comments' => $order->get_customer_note(),
        ];

        if ($primary_item) {
            $order_data['order_item_id'] = (int) $primary_item->get_id();
            $order_data['order_item_index'] = $order_data_item_index;

            $product = $primary_item->get_product();
            if ($product) {
                $order_data['product_id'] = $product->get_id();
                $order_data['product_name'] = $product->get_name();
            }

            $participants_count = $primary_item->get_meta('participants_count');
            if (!empty($participants_count)) {
                $order_data['participants_count'] = (int) $participants_count;
            }

            // Extract all selection data (days, hotel, etc.) via SelectionManager
            $order_data = array_merge($order_data, Selection\SelectionManager::flattenForOrderData($primary_item));
        }

        // Pull FieldBuilder checkout fields from order meta into order_data
        // so they get saved to participant meta on creation.
        foreach (FieldBuilder::getFields() as $field_id => $field_def) {
            $meta_key = $field_def['key'] ?? $field_id;
            if (isset($order_data[$field_id])) {
                continue; // already populated (e.g. by billing fields like email)
            }
            $value = $order->get_meta($meta_key);
            if ($value !== '' && $value !== null && $value !== false) {
                $order_data[$field_id] = $value;
            }
        }

        // Add used coupons if enabled via filter
        if (apply_filters('alttag_registrations_include_coupons', true)) {
            $used_coupons = $order->get_coupon_codes();
            $order_data['used_coupons'] = !empty($used_coupons) ? implode(', ', $used_coupons) : '';
        }

        // Add variable symbol if none available from invoice
        if (empty($order_data['variable_symbol'])) {
            $custom_variable_symbol = $this->generateCustomVariableSymbol($order);
            $order_data['variable_symbol'] = $custom_variable_symbol;

            if (!RegistrationContext::current()->hasInvoicing()) {
                $order->add_order_note(
                    sprintf(
                        __('Variable symbol generated: %s (no invoice plugin active)', 'alttag-registrations'),
                        $custom_variable_symbol
                    )
                );
            }
        }

        // Every line item after the first would otherwise inherit the order's
        // single variable symbol, and the QR / verification lookup resolves a
        // variable symbol to exactly one participant — the second ticket would
        // scan as the first. Item 0 keeps the order's symbol untouched, so
        // single-product orders are unaffected.
        if ($order_data_item_index > 0 && !empty($order_data['variable_symbol'])) {
            $order_data['variable_symbol'] .= '-' . ($order_data_item_index + 1);
        }

        /**
         * Filter to add event-specific fields to order data
         *
         * @param array $order_data The order data array
         * @param WC_Order $order The order object
         * @return array Modified order data
         */
        return apply_filters('alttag_registrations_order_data', $order_data, $order);
    }

    /**
     * Create or update participant
     *
     * @param array $order_data
     */
    public function createOrUpdateParticipant($order_data)
    {
        if (!isset($order_data['order_id'])) {
            return;
        }

        // A publish-only lookup misses participants for trashed orders and would create a duplicate.
        $order = wc_get_order($order_data['order_id']);
        if ($order !== false && in_array($order->get_status(), ['trash', 'archived'], true)) {
            return;
        }

        if (!apply_filters('alttag_registrations_enable_participants', true)) {
            return;
        }

        $participant = $this->findParticipantForItem($order_data);

        if (isset($participant->ID)) {
            $this->participantManager->updateParticipant((int) $participant->ID, $order_data);
        } else {
            $this->participantManager->createParticipant($order_data);
        }
    }

    /**
     * Resolve the participant belonging to one registration line item.
     *
     * @param array $order_data Item-scoped order data (see getOrderData())
     * @return \WP_Post|array The participant post, or [] when none exists yet
     */
    private function findParticipantForItem($order_data)
    {
        $item_id = (int) ($order_data['order_item_id'] ?? 0);
        $participant = $item_id
            ? $this->participantManager->getParticipantByOrderItemId($order_data['order_id'], $item_id)
            : $this->participantManager->getParticipantByOrderId($order_data['order_id']);

        // Participants created before per-item linking carry no order_item_id,
        // so the item-scoped lookup misses them and we would insert a
        // duplicate on the next status change. Only the order's FIRST
        // registration item may adopt such a participant.
        if ($item_id && !isset($participant->ID) && (int) ($order_data['order_item_index'] ?? 0) === 0) {
            $legacy = $this->participantManager->getParticipantByOrderId($order_data['order_id']);
            if (isset($legacy->ID) && get_post_meta($legacy->ID, 'order_item_id', true) === '') {
                $participant = $legacy;
            }
        }

        return $participant;
    }

    /**
     * Send ONE confirmation e-mail per registration line item.
     *
     * A mixed cart (livestream + in-person) carries different content per
     * item — a livestream grant vs a PDF ticket — so the old single
     * order-level mail left the second buyer without their ticket. Each mail
     * is flagged on its own item id, so repeated status changes never resend
     * and a single-item order still produces exactly one mail.
     *
     * Each send is wrapped in try/catch like
     * MultiParticipantModule::registerExtraParticipants(): one item's failure
     * must not block the remaining items.
     *
     * @param int   $order_id
     * @param array $items_data List of item-scoped order data arrays
     */
    private function sendConfirmationEmails($order_id, array $items_data)
    {
        // Orders processed before per-item flags existed carry only the
        // order-level flag, which stood for "the FIRST item's mail went out".
        // Honour it so existing orders don't get a duplicate confirmation.
        $legacy_sent = (bool) get_post_meta($order_id, '_confirmation_email_sent', true);
        $all_sent = true;

        foreach ($items_data as $order_data) {
            $item_id = (int) ($order_data['order_item_id'] ?? 0);
            $meta_key = $item_id
                ? '_confirmation_email_sent_item_' . $item_id
                : '_confirmation_email_sent';

            if (get_post_meta($order_id, $meta_key, true)) {
                continue;
            }
            if ($legacy_sent && (int) ($order_data['order_item_index'] ?? 0) === 0) {
                update_post_meta($order_id, $meta_key, true);
                continue;
            }

            do_action('alttag_registrations_order_completed', $order_data);

            try {
                $this->sendCustomEmail($order_data);
                update_post_meta($order_id, $meta_key, true);
            } catch (\Throwable $e) {
                // Leave the flag unset so the next status change retries this
                // item, and log — a silently missing ticket e-mail is worse.
                $all_sent = false;
                error_log(sprintf(
                    '[alttag-registrations] Confirmation email failed for order #%d item #%d: %s in %s:%d',
                    $order_id,
                    $item_id,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                ));
            }
        }

        // Keep the order-level flag as the "this order has been mailed"
        // marker for anything still reading it (and for the legacy branch
        // above) — only once every item actually went out.
        if ($all_sent) {
            update_post_meta($order_id, '_confirmation_email_sent', true);
        }
    }

    /**
     * Send custom email with order confirmation and attachments
     *
     * @param array $order_data Order data containing order details and customer information
     */
    public function sendCustomEmail($order_data)
    {
        // NOTE: Stripe receipt is NOT sent from here anymore. The old inline
        // call was hitting a race — this method runs on the earliest status
        // transition (`_status_changed(pending, processing)`), which fires
        // BEFORE woo-stripe-payment writes `_payment_intent_id` onto the
        // order, so `submitReceipt()` silently skipped for the buyer. It
        // now fires from `StripeManager::sendReceiptOnPaymentComplete()`
        // bound to `woocommerce_payment_complete`, which WC dispatches only
        // after the gateway has finalised payment and attached the intent.
        $send_email = apply_filters('alttag_registrations_enable_order_email', true, $order_data);
        if (!$send_email) {
            return;
        }
        $built = $this->buildOrderEmail($order_data);
        if ($built) {
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            wp_mail($order_data['email'], $built['subject'], $built['html'], $headers, $built['attachments']);
            $this->cleanupTemporaryFiles($built['attachments']);
            do_action('alttag_registrations_after_order_email', $built['order']);
        }
    }

    /**
     * Build the order-confirmation email (subject + HTML body + attachments)
     * without actually sending it. Callers (send flow, preview flow) share this.
     *
     * Fires `alttag_registrations_before_order_email` before building so that
     * language switching etc. applies; caller is responsible for firing the
     * corresponding `alttag_registrations_after_order_email` when done with
     * the returned data.
     *
     * @param array $order_data
     * @return array{subject:string,html:string,attachments:array,order:\WC_Order|null}|null
     */
    public function buildOrderEmail($order_data)
    {
        $order_id = $order_data['order_id'] ?? null;
        $order = $order_id ? wc_get_order($order_id) : null;

        // When resending for a specific participant (buyer OR extra), honour
        // that ID. Otherwise fall back to the buyer's participant looked up
        // by order_id. Without this override, extras end up receiving the
        // buyer's ticket / QR / variable_symbol in their confirmation email.
        $explicit_pid = isset($order_data['participant_id']) ? (int) $order_data['participant_id'] : 0;
        if ($explicit_pid > 0) {
            $participant_details = $this->participantManager->getParticipantDetails($explicit_pid);
            $participant = get_post($explicit_pid);
        } else {
            $participant = $this->participantManager->getParticipantByOrderId($order_id);
            $participant_details = $participant ? $this->participantManager->getParticipantDetails($participant->ID) : [];
        }
        $has_invoice = !empty($participant_details['invoice_url']);

        // Allow language switching before generating email content
        do_action('alttag_registrations_before_order_email', $order);
        $template = $this->settings->getEmailTemplate($order);
        $subject_template = $this->settings->getEmailSubject($order, $has_invoice);

        $email_data = ['order' => $order];

        $participants_enabled = apply_filters('alttag_registrations_enable_participants', true);
        if ($participants_enabled) {
            $email_data = $this->addParticipantData($email_data, $order_data);
        }

        $email_data = array_merge($email_data, $order_data);

        $email_content = $this->generateEmailContent($template, $subject_template, $email_data);
        $email_content = EmailWrapper::convertToTableLayout($email_content);

        $preheader = wp_strip_all_tags(substr($email_data['title'], 0, 100));
        $email_content = EmailWrapper::wrap($email_content, $preheader);

        $attachments = \Alttag\Registrations\Participant\Manager::prepareEmailAttachments(
            $participant_details,
            true,        // include ticket
            $has_invoice // include invoice only if it exists
        );

        return [
            'subject' => $email_data['title'],
            'html' => $email_content,
            'attachments' => $attachments,
            'order' => $order,
        ];
    }

    /**
     * Add participant data to email data array
     *
     * @param array $email_data Current email data
     * @param array $order_data Order data
     * @return array Updated email data with participant information
     */
    private function addParticipantData($email_data, $order_data)
    {
        // Honour explicit participant_id when the caller is targeting a
        // specific attendee (extras' confirmation emails). Falls back to the
        // buyer-by-order_id lookup for the default checkout flow.
        $explicit_pid = isset($order_data['participant_id']) ? (int) $order_data['participant_id'] : 0;
        if ($explicit_pid > 0) {
            $participant_meta = $this->participantManager->getParticipantDetails($explicit_pid);
        } else {
            $participant = $this->participantManager->getParticipantByOrderId($order_data['order_id']);
            $participant_meta = ($participant && isset($participant->ID))
                ? $this->participantManager->getParticipantDetails($participant->ID)
                : [];
        }

        return array_merge($email_data, [
            'country_name'          => $participant_meta['country_name']          ?? '',
            'variable_symbol'       => $participant_meta['variable_symbol']       ?? '',
            'qr_code_url'           => $participant_meta['qr_code_url']           ?? '',
            'ticket_url'            => $participant_meta['ticket_url']            ?? '',
            'ticket_full_file_path' => $participant_meta['ticket_full_file_path'] ?? '',
            'invoice_url'           => $participant_meta['invoice_url']           ?? '',
            'order'                 => $email_data['order'],
        ]);
    }

    /**
     * Generate email content using templates
     *
     * @param string $template Email template
     * @param string $subject_template Subject template
     * @param array $email_data Email data for template parsing
     * @return string Generated email content
     */
    private function generateEmailContent($template, $subject_template, &$email_data)
    {
        // Parse subject template
        $subject_parser = new TemplateParser($subject_template, $email_data);
        $email_data['title'] = $subject_parser->render();

        // Parse email template
        $parser = new TemplateParser($template, $email_data);
        return $parser->render();
    }

    /**
     * Clean up temporary attachment files
     *
     * @param array $attachments List of attachment file paths
     */
    private function cleanupTemporaryFiles($attachments = [])
    {
        if (empty($attachments)) {
            return;
        }

        foreach ($attachments as $attachment) {
            if (strpos($attachment, wp_upload_dir()['path']) === 0 && file_exists($attachment)) {
                unlink($attachment);
            }
        }
    }

    /**
     * Override thank you template
     *
     * @param string $template
     * @param string $template_name
     * @param array $args
     * @param string $template_path
     * @param string $default_path
     * @return string
     */
    public function overrideWooCommerceTemplates($template, $template_name, $args, $template_path, $default_path)
    {
        // Extract template parts
        $template_parts = explode('/', $template_name);
        $template_type = $template_parts[0] ?? '';
        $template_file = $template_parts[1] ?? '';

        // Build filter names based on template
        $filter_names = [
            sprintf(
                'alttag_registrations_%s_template',
                str_replace('-', '_', $template_type)
            ),
            sprintf(
                'alttag_registrations_%s_%s_template',
                str_replace('-', '_', $template_type),
                str_replace('-', '_', pathinfo($template_file, PATHINFO_FILENAME))
            )
        ];

        // Check each filter first
        foreach ($filter_names as $filter_name) {
            if (has_filter($filter_name)) {
                $custom_template = apply_filters(
                    $filter_name,
                    ALTTAG_REGISTRATIONS_PATH . '/templates/' . $template_file,
                    $template_name,
                    $args
                );

                // Verify custom template exists
                if (is_string($custom_template) && file_exists($custom_template)) {
                    return $custom_template;
                }
            }
        }

        // If no filter override, check if template exists in our templates/woocommerce directory
        $custom_wc_template = ALTTAG_REGISTRATIONS_PATH . '/templates/woocommerce/' . $template_name;
        if (file_exists($custom_wc_template)) {
            return $custom_wc_template;
        }

        return $template;
    }

    /**
     * Add invoice data
     *
     * @param array $set_invoice_data
     * @param WC_Order $order
     * @param string $type
     * @return array
     */
    /**
     * Readable invoice line for a registration.
     *
     * SuperFaktura builds the description from the raw order item meta, which put
     * "selected_session_slot: 18:35" on the invoice, and the line name carried the
     * pricing tier label appended for the shop. An invoice wants neither: the name
     * is the product, the description is what was actually booked.
     *
     * @param array     $item_data
     * @param \WC_Order  $order
     * @param mixed     $product
     * @param mixed     $item
     * @return array
     */
    public function cleanInvoiceItem($item_data, $order, $product, $item)
    {
        if (!is_array($item_data)) {
            return $item_data;
        }

        $product_id = 0;
        if (is_array($item) && isset($item['product_id'])) {
            $product_id = (int) $item['product_id'];
        } elseif (is_object($item) && method_exists($item, 'get_product_id')) {
            $product_id = (int) $item->get_product_id();
        }

        if (!$product_id || !get_post_meta($product_id, '_session_dates', true)) {
            return $item_data;
        }

        // Name without the pricing tier suffix the shop appends. Raw post title,
        // not get_the_title(), which would texturize the hyphens into dashes.
        $title = (string) get_post_field("post_title", $product_id);
        if ($title !== '') {
            $item_data['name'] = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
        }

        $meta = self::invoiceItemMeta($item);
        $lines = [];

        $date = $meta['selected_session'] ?? '';
        $date = is_array($date) ? reset($date) : $date;
        if ($date) {
            $lines[] = __('Date:', 'alttag-registrations') . ' ' . \Alttag\Registrations\format_date($date);
        }

        $slot = $meta['selected_session_slot'] ?? '';
        if (is_string($slot) && $slot !== '') {
            $label = $slot;
            $sessions = get_post_meta($product_id, '_session_dates', true);
            if (is_array($sessions)) {
                foreach ($sessions as $session) {
                    foreach ((array) ($session['slots'] ?? []) as $candidate) {
                        if (($candidate['time'] ?? '') === $slot && !empty($candidate['label'])) {
                            $label = (string) $candidate['label'];
                            break 2;
                        }
                    }
                }
            }
            $lines[] = __('Time slot:', 'alttag-registrations') . ' ' . $label;
        }

        $types = $meta['selected_participant_types_data'] ?? [];
        if (is_array($types) && $types) {
            $configured = get_post_meta($product_id, '_participant_types', true);
            $labels = [];
            foreach ((array) $configured as $row) {
                if (!empty($row['id'])) {
                    $labels[$row['id']] = (string) ($row['label'] ?? $row['id']);
                }
            }
            $parts = [];
            foreach ($types as $type_id => $count) {
                $count = (int) $count;
                if ($count > 0) {
                    $parts[] = $count . '× ' . ($labels[$type_id] ?? $type_id);
                }
            }
            if ($parts) {
                $lines[] = __('Participants:', 'alttag-registrations') . ' ' . implode(', ', $parts);
            }
        }

        $item_data['description'] = implode("\r\n", $lines);

        return apply_filters('alttag_registrations_invoice_item_data', $item_data, $order, $item);
    }

    /**
     * Order item meta as a plain key => value map.
     *
     * @param mixed $item
     * @return array
     */
    private static function invoiceItemMeta($item): array
    {
        if (is_array($item)) {
            return isset($item['item_meta']) && is_array($item['item_meta']) ? $item['item_meta'] : [];
        }

        if (is_object($item) && method_exists($item, 'get_meta_data')) {
            $meta = [];
            foreach ($item->get_meta_data() as $entry) {
                $data = $entry->get_data();
                $meta[$data['key']] = $data['value'];
            }
            return $meta;
        }

        return [];
    }

    public function addInvoiceData($set_invoice_data, $order, $type)
    {
        if (isset($set_invoice_data['order_no'])) {
            unset($set_invoice_data['order_no']);
        }

        // Set delivery date (date of taxable supply) same as created date
        $created_date = $order->get_date_created();
        if ($created_date) {
            $set_invoice_data['delivery'] = $created_date->format('Y-m-d');
        }

        // Set due date from Settings (per-product override allowed; default 14 days)
        $product_id = ctx()->withOrder($order)->productId();
        $due_days = (int) Settings::getValue('checkout.invoice_due_days', $product_id);
        if ($due_days > 0 && $created_date) {
            $due_date = clone $created_date;
            $due_date->modify('+' . $due_days . ' days');
            $set_invoice_data['due'] = $due_date->format('Y-m-d');
        }

        // Add participant name to invoice comment. Modules (e.g. MembershipModule)
        // can rewrite this label downstream via the alttag_registrations_invoice_data filter.
        $first_name = $order->get_billing_first_name();
        $last_name = $order->get_billing_last_name();
        $name_parts = array_filter([$first_name, $last_name]);

        if (!empty($name_parts)) {
            $participant_info = "\r\n" . __('Participant:', 'alttag-registrations') . ' ' . implode(' ', $name_parts);
            $set_invoice_data['comment'] = !empty($set_invoice_data['comment'])
                ? $set_invoice_data['comment'] . $participant_info
                : $participant_info;
        }

        // Add order notes to invoice comment
        $order_comments = $order->get_customer_note();
        if (!empty($order_comments)) {
            $order_notes_info = "\r\n" . __('Order notes:', 'alttag-registrations') . ' ' . $order_comments;
            $set_invoice_data['comment'] = !empty($set_invoice_data['comment'])
                ? $set_invoice_data['comment'] . $order_notes_info
                : $order_notes_info;
        }

        /**
         * Filter to modify invoice data
         *
         * @param array $set_invoice_data The invoice data array
         * @param WC_Order $order The order object
         * @param string $type The invoice type
         * @return array Modified invoice data
         */
        return apply_filters('alttag_registrations_invoice_data', $set_invoice_data, $order, $type);
    }

    /**
     * Add client data
     *
     * @param array $client_data
     * @param WC_Order $order
     * @return array
     */
    public function addClientData($client_data, $order)
    {
        $business_id = $order->get_meta('_billing_company_wi_id') ?? '';
        $full_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

        if ($business_id && $order->get_billing_company()) {
            $client_data['name'] = $order->get_billing_company();
        } else {
            $client_data['name'] = $full_name;
        }

        // SF plugin fills ico/dic/ic_dph from its own (unprefixed) order meta,
        // saved by its checkout hook. Checkout paths that bypass that hook
        // (see validateBusinessBillingFields) leave those keys empty even when
        // the values exist under the underscore-prefixed meta — backfill so
        // the invoice always carries IČO/DIČ/IČ DPH.
        $identifier_meta = [
            'ico'    => '_billing_company_wi_id',
            'dic'    => '_billing_company_wi_tax',
            'ic_dph' => '_billing_company_wi_vat',
        ];
        foreach ($identifier_meta as $client_key => $meta_key) {
            if (empty($client_data[$client_key])) {
                $meta_value = (string) $order->get_meta($meta_key);
                if ($meta_value !== '') {
                    $client_data[$client_key] = $meta_value;
                }
            }
        }

        /**
         * Filter to modify client data for invoice
         *
         * @param array $client_data The client data array
         * @param WC_Order $order The order object
         * @return array Modified client data
         */
        return apply_filters('alttag_registrations_client_data', $client_data, $order);
    }

    /**
     * Disable product and archive pages
     */
    public function disableProductAndArchivePages()
    {
        if (is_admin()) {
            return;
        }

        if (is_front_page()) {
            return;
        }

        if (is_product() || is_shop() || is_product_category() || is_product_tag()) {
            wp_redirect(home_url());
            exit();
        }
    }

    /**
     * Sync cart product type flags when product is added to cart
     */
    public function syncCartFlagsOnAdd($product_id, $cart, $session)
    {
        $session->set('cart_product_id', (int) $product_id);

        $pc = ProductConfig::get($product_id);
        $is_livestream = $pc ? $pc->isLivestream() : false;

        $_SESSION['cart_contains_inperson_attendance_product'] = $is_livestream ? 0 : 1;
        $session->set('cart_contains_inperson_attendance_product', $is_livestream ? 0 : 1);
        $_SESSION['cart_contains_online_attendance_product'] = $is_livestream ? 1 : 0;
        $session->set('cart_contains_online_attendance_product', $is_livestream ? 1 : 0);
    }

    /**
     * Sync cart product type flags on page load
     */
    public function syncCartFlags()
    {
        $cart = RegistrationContext::current()->cart();

        $_SESSION['cart_contains_inperson_attendance_product'] = $cart->hasInperson ? 1 : 0;
        $_SESSION['cart_contains_online_attendance_product'] = $cart->hasLivestream ? 1 : 0;
    }

    /**
     * Handle add to cart from URL and redirect to checkout
     */
    public function handleAddToCartFromURL()
    {
        if (!isset($_GET['add-to-cart']) || empty($_GET['add-to-cart'])) {
            return;
        }

        $product_id = absint($_GET['add-to-cart']);
        if (!$product_id) {
            return;
        }

        // Ensure WC is loaded and session started
        if (!did_action('woocommerce_init')) {
            WC()->frontend_includes();
            if (is_null(WC()->session)) {
                WC()->session = new WC_Session_Handler();
                WC()->session->init();
            }
            if (is_null(WC()->cart)) {
                WC()->cart = new WC_Cart();
            }
        }

        // Empty cart first
        WC()->cart->empty_cart();

        // Add the product to cart
        WC()->cart->add_to_cart($product_id);

        do_action('alttag_registrations_add_to_cart', $product_id, WC()->cart, WC()->session);

        // Redirect to checkout
        wp_safe_redirect(wc_get_checkout_url());
        exit();
    }

    /**
     * Make virtual products skip order processing status
     *
     * @param bool $needs_processing
     * @param WC_Product $product
     * @param int $order_id
     * @return bool
     */
    public function wcOrderItemNeedsProcessing($needs_processing, $product, $order_id)
    {
        return false;
    }

    /**
     * Localize WooCommerce tax rate labels for the current locale.
     */
    public function localizeTaxLabel($label)
    {
        if ($label === 'VAT' && strpos(determine_locale(), 'sk') === 0) {
            return __('VAT', 'alttag-registrations');
        }
        return $label;
    }

    /**
     * Append the rate percentage to each cart-tax-totals label so the
     * checkout/cart breakdown reads "DPH 5%" instead of just "DPH". Rate
     * values come from WC_Tax::get_rate_percent (which returns e.g. "5 %") —
     * we strip its whitespace and locale-specific spacing to keep the suffix
     * predictable.
     */
    public function appendRateToTaxLabel($tax_totals, $cart)
    {
        if (!is_array($tax_totals)) {
            return $tax_totals;
        }
        foreach ($tax_totals as $code => $tax) {
            if (empty($tax->tax_rate_id)) {
                continue;
            }
            $rate = \WC_Tax::get_rate_percent_value($tax->tax_rate_id);
            if (!$rate || (float) $rate <= 0) {
                continue;
            }
            $percent = rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.') . '%';
            $tax->label = trim($tax->label) . ' ' . $percent;
        }
        return $tax_totals;
    }

    /**
     * Modify checkout fields
     *
     * @param array $fields
     * @return array
     */
    public function modifyCheckoutFields($fields)
    {
        // Keep validation in sync with the form. Native address fields follow
        // their FieldBuilder "Show in -> Checkout" checkbox.
        foreach (CheckoutManager::getHiddenBillingFields() as $field_key) {
            unset($fields['billing'][$field_key]);
        }

        // SuperFaktúra-related billing fields render together as a logical group
        // (Fakturovať na firmu → company name → IČO → DIČ → IČ DPH). The
        // FieldBuilder priorities used for ticket layout don't match this order,
        // so override priorities here just for the checkout form.
        $sf_field_priority = [
            'wi_as_company' => 24,
            'billing_company' => 25,
            'billing_company_wi_id' => 26,
            'billing_company_wi_tax' => 27,
            'billing_company_wi_vat' => 28,
        ];

        $sf_field_map = [
            'billing_company' => 'company_name',
            'wi_as_company' => 'wi_as_company',
            'billing_company_wi_id' => 'company_wi_id',
            'billing_company_wi_tax' => 'company_wi_tax',
            'billing_company_wi_vat' => 'company_wi_vat',
        ];

        $sf_active = FieldBuilder::isSuperfakturaActive();

        foreach ($sf_field_map as $billing_key => $field_builder_key) {
            if (!isset($fields['billing'][$billing_key])) {
                continue;
            }
            $fb_field = FieldBuilder::getField($field_builder_key);
            if ($fb_field) {
                // If SF plugin is NOT active and the FieldBuilder field is not
                // explicitly set to be shown at checkout, hide the native WC field.
                // This lets admins turn off billing_company (and SF siblings) by
                // simply removing "checkout" from the FB field's contexts.
                if (!$sf_active && isset($fb_field['contexts']) && is_array($fb_field['contexts'])
                    && !in_array('checkout', $fb_field['contexts'], true)) {
                    unset($fields['billing'][$billing_key]);
                    continue;
                }
                $fields['billing'][$billing_key]['label'] = FieldBuilder::getFieldLabel($fb_field);
                $fields['billing'][$billing_key]['priority'] = $sf_field_priority[$billing_key]
                    ?? ($fb_field['priority'] ?? 99);
            }
        }

        // Add filter to modify checkout fields
        return apply_filters('alttag_registrations_modify_checkout_fields', $fields);
    }

    /**
     * Set SF field labels and priorities at the billing fields level.
     * Runs after SF plugin (p:20 vs SF's p:10) to ensure our labels take precedence.
     */
    public function modifyBillingFieldLabels($fields)
    {
        $sf_field_map = [
            'billing_company' => 'company_name',
            'wi_as_company' => 'wi_as_company',
            'billing_company_wi_id' => 'company_wi_id',
            'billing_company_wi_tax' => 'company_wi_tax',
            'billing_company_wi_vat' => 'company_wi_vat',
        ];

        foreach ($sf_field_map as $billing_key => $field_builder_key) {
            if (!isset($fields[$billing_key])) {
                continue;
            }
            $fb_field = FieldBuilder::getField($field_builder_key);
            if ($fb_field) {
                $fields[$billing_key]['label'] = FieldBuilder::getFieldLabel($fb_field);
                $fields[$billing_key]['priority'] = $fb_field['priority'] ?? 99;
            }
        }

        return $fields;
    }

    /**
     * Override field labels at render time.
     * Runs after Elementor Pro's modify_form_field (p:70) which overwrites labels
     * from its saved widget config.
     */
    public function overrideFieldLabelsAtRender($args, $key)
    {
        $sf_field_map = [
            'billing_company' => 'company_name',
            'wi_as_company' => 'wi_as_company',
            'billing_company_wi_id' => 'company_wi_id',
            'billing_company_wi_tax' => 'company_wi_tax',
            'billing_company_wi_vat' => 'company_wi_vat',
        ];

        if (isset($sf_field_map[$key])) {
            $fb_field = FieldBuilder::getField($sf_field_map[$key]);
            if ($fb_field) {
                $args['label'] = FieldBuilder::getFieldLabel($fb_field);
            }
        }

        return $args;
    }

    /**
     * Restore the Elementor Pro checkout widget's coupon section on the first
     * checkout load after add-to-cart.
     *
     * Selection\SelectionManager::adjustPricing() leaves cart_item price at 0 until
     * session selections exist, and selections only land in the session via the
     * woocommerce_checkout_update_order_review AJAX — which fires *after* the
     * initial HTML render. So on the first checkout render after add-to-cart the
     * total stays at 0.00, WC()->cart->needs_payment() returns false, and
     * ElementorPro\Modules\Woocommerce\Widgets\Checkout::should_render_coupon()
     * suppresses the coupon HTML. A refresh works because the AJAX has populated
     * the session by then.
     *
     * We force needs_payment to true only when the filter is being called from
     * should_render_coupon, leaving all other needs_payment callers (payment
     * gateways, redirects, etc.) untouched.
     */
    public function forceCouponSectionOnFirstLoad($needs_payment, $cart)
    {
        if ($needs_payment || !$cart instanceof \WC_Cart || $cart->is_empty()) {
            return $needs_payment;
        }
        if (!function_exists('wc_coupons_enabled') || !wc_coupons_enabled()) {
            return $needs_payment;
        }
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15) as $frame) {
            $caller = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            if (strpos($caller, 'should_render_coupon') !== false) {
                return true;
            }
        }
        return $needs_payment;
    }

    /**
     * Add GDPR consent field
     */
    public function addGDPRConsentField()
    {
        if (!apply_filters('alttag_registrations_enable_gdpr_consent', true)) {
            return;
        }

        $gdpr_link = apply_filters('alttag_registrations_gdpr_link', '');
        if (empty($gdpr_link)) {
            return;
        }

        // Link text is sentence-internal — translations must use the case
        // required by the host sentence ("v súlade s %s" → instrumental in
        // Slovak). Sites linking to a non-GDPR page (e.g. a generic privacy
        // policy) can override via this filter.
        $gdpr_link_text = apply_filters(
            'alttag_registrations_gdpr_link_text',
            __('GDPR regulations', 'alttag-registrations')
        );

        $gdpr_link = '<a href="' . esc_url($gdpr_link) . '" class="gdpr-policy-link" target="_blank">'
            . esc_html($gdpr_link_text) . '</a>';

        woocommerce_form_field('gdpr_consent', [
            'type'     => 'checkbox',
            'class'    => ['form-row-wide'],
            'label'    => sprintf(__('I agree to the processing of my personal data in accordance with %s.', 'alttag-registrations'), $gdpr_link),
            'required' => true,
        ]);
    }

    /**
     * Validate GDPR consent
     */
    public function validateGDPRConsent()
    {
        if (!apply_filters('alttag_registrations_enable_gdpr_consent', true)) {
            return;
        }

        if (!isset($_POST['gdpr_consent'])) {
            wc_add_notice(__('Please agree to the GDPR consent to proceed with the order.', 'alttag-registrations'), 'error');
        }
    }

    /**
     * Add Terms and Conditions consent field
     */
    public function addTermsConsentField()
    {
        if (!apply_filters('alttag_registrations_enable_terms_consent', true)) {
            return;
        }

        $terms_link = apply_filters('alttag_registrations_terms_link', '');
        if (empty($terms_link)) {
            return;
        }

        $terms_link = '<a href="' . $terms_link . '" class="terms-conditions-link" target="_blank">'
            . __('Terms and Conditions', 'alttag-registrations') . '</a>';

        woocommerce_form_field('terms_consent', [
            'type'     => 'checkbox',
            'class'    => ['form-row-wide'],
            'label'    => sprintf(
                __('I agree to the processing of my personal data in accordance with the %s.', 'alttag-registrations'),
                $terms_link
            ),
            'required' => true,
        ]);
    }

    /**
     * Validate Terms and Conditions consent
     */
    public function validateTermsConsent()
    {
        if (!apply_filters('alttag_registrations_enable_terms_consent', true)) {
            return;
        }

        if (!isset($_POST['terms_consent'])) {
            wc_add_notice(
                __('Please agree to the Terms and Conditions to proceed with the order.', 'alttag-registrations'),
                'error'
            );
        }
    }

    /**
     * Validate SuperFaktura business-invoicing fields.
     *
     * Fires on `woocommerce_checkout_process` alongside SF plugin's own
     * validator. Required because some checkout implementations
     * (Elementor Pro's Woo Checkout, block-based checkout, custom Ajax
     * submitters) bypass SF's `add_filter('woocommerce_checkout_process')`
     * registration and let orders through without the Slovak-invoicing
     * fields — even when SF's option `woocommerce_sf_add_company_billing_fields_*`
     * is set to `required`.
     *
     * Required check covers only the address fields (street, postcode,
     * city) whenever `wi_as_company` is checked. Company name, IČO, DIČ
     * and IČ DPH presence is left to the SF plugin's own checkout_process(),
     * which honours the `woocommerce_sf_add_company_billing_fields_*`
     * options — duplicating it here would double the notices. Downstream
     * projects can override the required field map via the
     * `alttag_registrations_business_billing_required_fields` filter, or
     * disable the check entirely via
     * `alttag_registrations_enable_business_billing_validation`.
     */
    /**
     * Keys of the checkout fields that are actually rendered.
     *
     * Returns null when the field list cannot be determined, in which case the
     * caller should validate everything rather than silently skip checks.
     *
     * @return array<string,true>|null
     */
    private static function renderedCheckoutFieldKeys(): ?array
    {
        if (!function_exists('WC') || !WC()->checkout()) {
            return null;
        }

        $fields = WC()->checkout()->get_checkout_fields();
        if (!is_array($fields) || !$fields) {
            return null;
        }

        $keys = [];
        foreach ($fields as $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach (array_keys($group) as $key) {
                $keys[$key] = true;
            }
        }

        return $keys ?: null;
    }

    /**
     * SuperFaktúra option keys for the company billing fields it validates.
     *
     * @return string[]
     */
    private static function sfCompanyRequirementOptions(): array
    {
        return [
            'woocommerce_sf_add_company_billing_fields_name',
            'woocommerce_sf_add_company_billing_fields_id',
            'woocommerce_sf_add_company_billing_fields_tax',
            'woocommerce_sf_add_company_billing_fields_vat',
        ];
    }

    /**
     * Stop SuperFaktúra demanding company billing data from private customers.
     *
     * WC_SuperFaktura::checkout_process() (includes/class-wc-superfaktura.php,
     * `woocommerce_checkout_process`, default priority) raises "%s is a required
     * field." for company name / IČO / DIČ / IČ DPH whenever its per-field
     * option is set to 'required'. It never looks at its own `wi_as_company`
     * checkbox, so a private customer who leaves one ordinary field blank gets
     * the whole company block listed in the error box next to the real error.
     * WooCommerce prefixes billing labels with `_x('Billing %s')` in validation
     * messages, so in Slovak every one of them reads "Fakturačné …".
     *
     * The requirement is suspended by filtering the four options to 'optional'
     * for the duration of SF's callback only — bracketed at priorities 9 and 11
     * — rather than unhooking it, because SF's callback is registered on an
     * instance we have no handle on, and because the rest of SF (the invoice,
     * the VAT-exemption logic) must keep reading the real settings.
     *
     * Our own validateBusinessBillingFields() is unaffected: it only puts
     * company fields into $required when `wi_as_company` is checked, which is
     * exactly when this suspension does not apply.
     */
    public function suspendSfCompanyRequirements()
    {
        if (!empty($_POST['wi_as_company'])) {
            return;
        }
        if (!apply_filters('alttag_registrations_relax_sf_company_validation', true)) {
            return;
        }

        foreach (self::sfCompanyRequirementOptions() as $option) {
            add_filter('pre_option_' . $option, [$this, 'forceSfFieldOptional'], 999);
        }
    }

    public function restoreSfCompanyRequirements()
    {
        foreach (self::sfCompanyRequirementOptions() as $option) {
            remove_filter('pre_option_' . $option, [$this, 'forceSfFieldOptional'], 999);
        }
    }

    /**
     * 'optional' keeps the field rendered but unvalidated. 'no' would remove it
     * from the checkout entirely, losing data a customer already typed.
     */
    public function forceSfFieldOptional()
    {
        return 'optional';
    }

    public function validateBusinessBillingFields()
    {
        if (!apply_filters('alttag_registrations_enable_business_billing_validation', true)) {
            return;
        }

        $as_company = !empty($_POST['wi_as_company']);

        // Fields WC core covers — skip our notice so we don't double up.
        $core_handled = apply_filters(
            'alttag_registrations_universal_billing_handled_by_core',
            ['billing_first_name', 'billing_last_name', 'billing_email', 'billing_country', 'billing_phone']
        );

        // Fields SF plugin covers when its option is 'required'.
        $sf_handled = apply_filters('alttag_registrations_company_billing_handled_by_sf', [
            'billing_company'        => 'woocommerce_sf_add_company_billing_fields_name',
            'billing_company_wi_id'  => 'woocommerce_sf_add_company_billing_fields_id',
            'billing_company_wi_tax' => 'woocommerce_sf_add_company_billing_fields_tax',
            'billing_company_wi_vat' => 'woocommerce_sf_add_company_billing_fields_vat',
        ]);

        // Universal address fields — only my filter covers these on WC core checkout.
        $required = [
            'billing_address_1' => __('Street address', 'alttag-registrations'),
            'billing_postcode'  => __('Postal code', 'alttag-registrations'),
            'billing_city'      => __('City', 'alttag-registrations'),
        ];

        // Company billing — only when the customer checked "as company".
        if ($as_company) {
            $required['billing_company']        = __('Company name', 'alttag-registrations');
            $required['billing_company_wi_id']  = __('Business ID', 'alttag-registrations');
            $required['billing_company_wi_tax'] = __('Tax ID', 'alttag-registrations');
        }

        $required = apply_filters(
            'alttag_registrations_business_billing_required_fields',
            $required
        );

        // A field that is not rendered can never be filled in, so validating it
        // would block every checkout. Only validate what the customer can see.
        $rendered = self::renderedCheckoutFieldKeys();

        foreach ($required as $field_key => $label) {
            if ($rendered !== null && !isset($rendered[$field_key])) {
                continue;
            }
            // Skip if WC core or SF plugin already covers this field.
            if (in_array($field_key, $core_handled, true)) {
                continue;
            }
            if (isset($sf_handled[$field_key]) && get_option($sf_handled[$field_key]) === 'required') {
                continue;
            }
            $value = trim((string) ($_POST[$field_key] ?? ''));
            if ($value === '') {
                $msg_template = isset($sf_handled[$field_key])
                    ? __('%s is required when purchasing as a company.', 'alttag-registrations')
                    : __('%s is a required field.', 'alttag-registrations');
                wc_add_notice(
                    sprintf($msg_template, '<strong>' . esc_html($label) . '</strong>'),
                    'error'
                );
            }
        }

        // Format checks on the Slovak-invoicing identifiers. SF plugin only
        // checks presence, never format, so a typo like an alphanumeric IČO
        // would otherwise reach the SuperFaktúra invoice.
        $format_rules = apply_filters(
            'alttag_registrations_business_billing_format_rules',
            [
                'billing_company_wi_id'  => [
                    'pattern' => '/^[0-9]{6,8}$/',
                    'label'   => __('Business ID', 'alttag-registrations'),
                    'hint'    => __('must contain 6 to 8 digits', 'alttag-registrations'),
                ],
                'billing_company_wi_tax' => [
                    'pattern' => '/^[0-9]{1,12}$/',
                    'label'   => __('Tax ID', 'alttag-registrations'),
                    'hint'    => __('must contain digits only (max. 12)', 'alttag-registrations'),
                ],
                'billing_company_wi_vat' => [
                    'pattern' => '/^[A-Z]{2}[0-9]{2,12}$/',
                    'label'   => __('VAT ID', 'alttag-registrations'),
                    'hint'    => __('must start with a country prefix followed by digits (e.g. SK1234567890)', 'alttag-registrations'),
                ],
            ]
        );

        foreach ($format_rules as $field_key => $rule) {
            $value = trim((string) ($_POST[$field_key] ?? ''));
            if ($value === '' || preg_match($rule['pattern'], $value)) {
                continue;
            }
            wc_add_notice(
                sprintf(
                    /* translators: 1: field label, 2: format hint */
                    __('%1$s %2$s.', 'alttag-registrations'),
                    '<strong>' . esc_html($rule['label']) . '</strong>',
                    esc_html($rule['hint'])
                ),
                'error'
            );
        }
    }

    /**
     * Reset error-notice link colour on checkout/order-received pages so the
     * list reads as a single block of text and not as a sequence of blue
     * underlined anchors (WC's default form-field link styling leaks here).
     */
    public function enqueueCheckoutErrorStyle()
    {
        if (!function_exists('is_checkout')) {
            return;
        }
        if (!is_checkout() && !is_wc_endpoint_url('order-received')) {
            return;
        }
        $css_path = ALTTAG_REGISTRATIONS_PATH . '/assets/css/checkout-error-style.css';
        wp_enqueue_style(
            'alttag-checkout-error-style',
            ALTTAG_REGISTRATIONS_URL . 'assets/css/checkout-error-style.css',
            [],
            file_exists($css_path) ? (string) filemtime($css_path) : '1.0.0'
        );
    }

    /**
     * Validate livestream email duplicates.
     * Skipped when CheckoutManager handles per-product + per-day validation.
     */
    public function validateLivestreamEmailDuplicates()
    {
        // CheckoutManager::validateDuplicateEmailRegistrations handles this more
        // intelligently (per-product, per-day overlap). Skip this legacy check.
        return;

        $email = sanitize_email($_POST['billing_email'] ?? '');
        if (empty($email)) {
            return;
        }

        // Get current cart product SKU for matching across translations
        $cart_sku = null;
        $cart = RegistrationContext::current()->cart();
        foreach ($cart->productIds as $pid) {
            $product = wc_get_product($pid);
            if ($product) {
                $cart_sku = $product->get_sku();
                break;
            }
        }

        // Find existing livestream participants with the same email and product SKU
        $meta_query = [
            'relation' => 'AND',
            ['key' => 'email', 'value' => $email, 'compare' => '='],
            ['key' => 'is_livestream_user', 'value' => '1', 'compare' => '='],
        ];

        // Match by SKU: find all product IDs with the same SKU
        if ($cart_sku) {
            $matching_product_ids = wc_get_products([
                'sku' => $cart_sku,
                'return' => 'ids',
                'limit' => -1,
            ]);
            if (!empty($matching_product_ids)) {
                $meta_query[] = [
                    'key' => 'product_id',
                    'value' => array_map('strval', $matching_product_ids),
                    'compare' => 'IN',
                ];
            }
        }

        $existing_participants = get_posts([
            'post_type' => 'participant',
            'meta_query' => $meta_query,
            'numberposts' => 1,
        ]);

        if (!empty($existing_participants)) {
            $pState = ParticipantState::get($existing_participants[0]->ID);
            $existing_order = $pState ? $pState->order() : null;

            if ($existing_order && in_array($existing_order->get_status(), ['completed', 'processing'])) {
                wc_add_notice(
                    sprintf(
                        __('Email %s is already registered for livestream access. Please use a different email address.', 'alttag-registrations'),
                        $email
                    ),
                    'error'
                );
            }
        }
    }

    /**
     * Save GDPR consent to order
     *
     * @param int $order_id
     */
    public function saveGDPRConsent($order_id)
    {
        if (!apply_filters('alttag_registrations_enable_gdpr_consent', true)) {
            return;
        }

        if (isset($_POST['gdpr_consent'])) {
            update_post_meta($order_id, '_gdpr_consent', 'yes');
        }
    }

    /**
     * Save current language to order (for Polylang integration)
     *
     * @param \WC_Order $order The order object
     * @param array $data Checkout data
     */
    public function saveLanguageToOrder($order, $data)
    {
        // Only save if Polylang is active
        if (!RegistrationContext::current()->hasPolylang()) {
            return;
        }

        $order->update_meta_data('_language', ctx()->currentLanguage());
    }

    /**
     * Set SuperFaktura invoice language based on order language (Polylang)
     *
     * SuperFaktura uses 3-letter language codes:
     * slo, cze, eng, deu, nld, hrv, hun, pol, rom, rus, slv, spa, ita, ukr
     *
     * @param string $language Current language
     * @param int $order_id Order ID
     * @param string $woocommerce_sf_invoice_language SuperFaktura language setting
     * @return string Modified language
     */
    public function setSuperfakturaInvoiceLanguage($language, $order_id, $woocommerce_sf_invoice_language)
    {
        // Only modify if SuperFaktura is set to use WPML/Polylang language
        if ($woocommerce_sf_invoice_language !== 'wpml') {
            return $language;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return $language;
        }

        return ctx()->withOrder($order)->superfakturaLanguageCode($language);
    }

    /**
     * Save company data to session (required for elementor checkout form)
     */
    public function saveDataRequiredForElementorCheckoutFormToSession()
    {
        $post_data = $_POST['post_data'] ?? [];
        if (empty($post_data)) {
            return;
        }

        $data = $this->parsePostData($post_data);
        if (!session_id()) {
            session_start();
        }

        $_SESSION['wi_as_company'] = $data['wi_as_company'] ?? '';
        $_SESSION['billing_company'] = $data['billing_company'] ?? '';
        $_SESSION['billing_company_wi_id'] = $data['billing_company_wi_id'] ?? '';
        $_SESSION['billing_company_wi_vat'] = $data['billing_company_wi_vat'] ?? '';
        $_SESSION['billing_company_wi_tax'] = $data['billing_company_wi_tax'] ?? '';
    }

    /**
     * Save superfaktura fields to session
     */
    public function saveSuperfakturaFieldsToSession($post_data)
    {
        $data = $this->parsePostData($post_data);
        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        WC()->session->set('wi_as_company', $data['wi_as_company'] ?? '');
        WC()->session->set('billing_company', $data['billing_company'] ?? '');
        WC()->session->set('billing_company_wi_id', $data['billing_company_wi_id'] ?? '');
        WC()->session->set('billing_company_wi_vat', $data['billing_company_wi_vat'] ?? '');
        WC()->session->set('billing_company_wi_tax', $data['billing_company_wi_tax'] ?? '');
    }

    /**
     * Parse post data
     *
     * @param string $post_data
     * @return array
     */
    private function parsePostData($post_data)
    {
        if (is_array($post_data)) {
            return $post_data;
        }

        $data = [];
        parse_str(wp_unslash((string) $post_data), $data);
        return $data;
    }

    public function forceCompanyFieldToBeOptional()
    {
        return 'optional';
    }

    public function forceAddress2FieldToBeHidden()
    {
        return 'hidden';
    }

    public function handleResendEmail()
    {
        if (!isset($_GET['resend_confirmation_email']) || empty($_GET['resend_confirmation_email'])) {
            return;
        }

        $participant_id = absint($_GET['resend_confirmation_email']);

        $participant_details = $this->participantManager->getParticipantDetails($participant_id);
        if (!$participant_details) {
            return;
        }

        // Set up product context so template can resolve event name, colors, etc.
        $product_id = $participant_details['product_id'] ?? null;
        if ($product_id) {
            ctx()->withProduct($product_id);
        }

        $order_id = $participant_details['order_id'] ?? null;
        $order = $order_id ? wc_get_order($order_id) : null;

        // Set language from participant for email template
        $participant_language = $participant_details['language'] ?? '';
        if (!empty($participant_language) && $order) {
            $order->update_meta_data('_language', $participant_language);
        }

        // Use participant data with order status
        $order_data = $participant_details;
        $order_data['participant_id'] = $participant_id;
        if ($order) {
            $order_data['order_status'] = $order->get_status();
        }

        $this->sendCustomEmail($order_data);
    }

    /**
     * Push purchase data to dataLayer for analytics tracking
     *
     * @param int $order_id The order ID
     * @return void
     */
    public function pushPurchaseToDataLayer($order_id)
    {
        if (empty($order_id)) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order instanceof \WC_Order) {
            return;
        }

        // Check if this purchase was already pushed to dataLayer
        $datalayer_pushed = get_post_meta($order_id, '_datalayer_purchase_pushed', true);
        if ($datalayer_pushed) {
            return;
        }

        // Get order items
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();

            if (!$product instanceof \WC_Product) {
                continue;
            }

            $items[] = [
                'item_id' => $product->get_id(),
                'item_name' => $item->get_name(),
                'price' => (float) $order->get_item_total($item, false),
                'quantity' => $item->get_quantity(),
            ];
        }

        // Prepare purchase data
        $purchase_data = [
            'transaction_id' => $order->get_order_number(),
            'value' => (float) $order->get_total(),
            'tax' => (float) $order->get_total_tax(),
            'shipping' => (float) $order->get_shipping_total(),
            'currency' => $order->get_currency(),
            'items' => $items,
        ];

        /**
         * Filter the purchase data before pushing to dataLayer
         *
         * @param array $purchase_data The purchase data
         * @param WC_Order $order The order object
         * @return array Modified purchase data
         */
        $purchase_data = apply_filters('alttag_registrations_datalayer_purchase', $purchase_data, $order);

        // Mark this order as pushed to dataLayer
        update_post_meta($order_id, '_datalayer_purchase_pushed', time());

        // Output the dataLayer push script
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            dataLayer.push({ ecommerce: null });  // Clear previous ecommerce object
            dataLayer.push({
                event: 'purchase',
                ecommerce: <?php echo wp_json_encode($purchase_data); ?>
            });
        </script>
        <?php
    }

    /**
     * Skip invoice creation for zero total orders
     *
     * @param bool $skip_invoice Current skip status
     * @param \WC_Order $order Order object
     * @return bool Whether to skip invoice creation
     */
    public function skipInvoiceForZeroTotalOrders($skip_invoice, $order)
    {
        // Check if order exists and is valid
        if (!$order instanceof \WC_Order) {
            return $skip_invoice;
        }

        // Skip invoice creation for zero total orders
        if (abs(floatval($order->get_total())) === 0.0) {
            return true;
        }

        return $skip_invoice;
    }

    /**
     * Strip the trailing parenthesised amount from SuperFaktura discount
     * line names so the invoice shows just "Zľava" instead of "Zľava (0 EUR)".
     *
     * @param array $discount_data Discount line as built by SuperFaktura.
     * @param mixed $order         Order context (passed by SF, may be empty on
     *                             non-order discounts — bail out in that case).
     * @return array
     */
    public function cleanDiscountData($discount_data, $order)
    {
        if (!$order || empty($discount_data) || !is_array($discount_data)) {
            return $discount_data;
        }

        if (isset($discount_data['description'])) {
            $discount_data['description'] = preg_replace(
                '/\s*\([^)]*\)\s*$/',
                '',
                (string) $discount_data['description']
            );
        }

        if (isset($discount_data['name'])) {
            $discount_data['name'] = preg_replace(
                '/\s*\([^)]*\)\s*$/',
                '',
                (string) $discount_data['name']
            );
        }

        return $discount_data;
    }

    /**
     * Generate custom variable symbol for zero total orders
     * Similar to how manual members get "M" prefix, zero total orders get "FREE" prefix
     *
     * @param \WC_Order $order Order object
     * @return string Generated variable symbol
     */
    private function generateCustomVariableSymbol($order)
    {
        $order_number = $order->get_order_number();

        // Get prefix from product settings, fallback to SKU-based, fallback to FREE
        $prefix = 'FREE';
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $ctx = \Alttag\Registrations\RegistrationContext::forProduct($product->get_id());
                $prefix = $ctx->variableSymbolPrefix('FREE');
                break;
            }
        }
        $prefix = apply_filters('alttag_registrations_zero_total_variable_symbol_prefix', $prefix);

        $variable_symbol = $prefix . $order_number;

        /**
         * Filter the generated variable symbol for zero total orders
         *
         * @param string $variable_symbol Generated variable symbol
         * @param \WC_Order $order Order object
         * @return string Modified variable symbol
         */
        return apply_filters('alttag_registrations_zero_total_variable_symbol', $variable_symbol, $order);
    }

    /**
     * Handle SuperFaktura payment callback and set order status to completed
     */
    public function handleSuperfakturaPaymentCallback()
    {
        // Early return if not a SuperFaktura payment callback
        if (!isset($_GET['callback']) || $_GET['callback'] !== 'wc_sf_order_paid') {
            return;
        }

        // Validate required parameters
        if (!isset($_GET['invoice_id']) || !isset($_GET['secret_key'])) {
            return;
        }

        // Sanitize input parameters
        $invoice_id = sanitize_text_field(wp_unslash($_GET['invoice_id']));
        $secret_key = sanitize_text_field(wp_unslash($_GET['secret_key']));

        // Validate secret key
        $stored_secret_key = get_option('woocommerce_sf_sync_secret_key', false);
        if (!$stored_secret_key || $secret_key !== $stored_secret_key) {
            return;
        }

        // Find order by SuperFaktura invoice ID
        $order = null;
        global $wpdb;

        // Check HPOS meta table first
        $hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
        if ($wpdb->get_var("SHOW TABLES LIKE '$hpos_meta_table'") === $hpos_meta_table) {
            $meta_keys = array('wc_sf_internal_regular_id', 'wc_sf_internal_proforma_id', 'wc_sf_internal_cancel_id');

            foreach ($meta_keys as $meta_key) {
                $result = $wpdb->get_row($wpdb->prepare(
                    "SELECT order_id FROM $hpos_meta_table WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                    $meta_key,
                    $invoice_id
                ));

                if ($result) {
                    try {
                        $order = wc_get_order($result->order_id);
                        if ($order && $order instanceof \WC_Order) {
                            break;
                        }
                    } catch (Exception $e) {
                        continue;
                    }
                }
            }
        }

        // Fallback to traditional postmeta if not found in HPOS
        if (!$order) {
            $meta_keys = array('wc_sf_internal_regular_id', 'wc_sf_internal_proforma_id', 'wc_sf_internal_cancel_id');

            foreach ($meta_keys as $meta_key) {
                $order_posts = get_posts(array(
                    'post_type' => 'shop_order',
                    'post_status' => array_keys(wc_get_order_statuses()),
                    'meta_query' => array(
                        array(
                            'key' => $meta_key,
                            'value' => $invoice_id,
                            'compare' => '='
                        )
                    ),
                    'numberposts' => 1,
                    'fields' => 'ids'
                ));

                if (!empty($order_posts)) {
                    try {
                        $order = wc_get_order($order_posts[0]);
                        if ($order && $order instanceof \WC_Order) {
                            break;
                        }
                    } catch (Exception $e) {
                        continue;
                    }
                }
            }
        }

        // Update order status if found
        if ($order && $order instanceof \WC_Order) {
            $order->update_status('completed', 'SuperFaktura payment confirmed via callback');
        }
    }
}
