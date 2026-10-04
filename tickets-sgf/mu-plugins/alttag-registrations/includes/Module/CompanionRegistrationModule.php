<?php

namespace Alttag\Registrations\Module;

use Alttag\Registrations\Core;

if (!defined('ABSPATH')) {
    exit;
}

class CompanionRegistrationModule extends AbstractModule
{
    public function getId(): string
    {
        return 'companion';
    }

    public function getName(): string
    {
        return __('Companion Registration', 'alttag-registrations');
    }

    public function getDescription(): string
    {
        return __('Register a companion (colleague, guest) alongside the main registrant. Creates a separate participant with ticket and sends confirmation email.', 'alttag-registrations');
    }

    public function getSettingsTab(): ?string
    {
        return 'modules';
    }

    public function hasProductToggle(): bool
    {
        return true;
    }

    public function getSettingsFields(): array
    {
        return [
            'companion' => [
                'companion_label' => [
                    'label' => __('Companion Label', 'alttag-registrations'),
                    'type' => 'text',
                    'default' => __('companion', 'alttag-registrations'),
                    'description' => __('Label for companion (e.g. "colleague", "companion", "guest")', 'alttag-registrations'),
                ],
                'enable_companion_email' => [
                    'label' => __('Send confirmation email to companion', 'alttag-registrations'),
                    'type' => 'checkbox',
                    'default' => true,
                    'description' => __('Send ticket and confirmation email to the companion after registration', 'alttag-registrations'),
                ],
            ],
        ];
    }

    public function registerHooks(): void
    {
        // Checkout
        add_filter('woocommerce_checkout_fields', [$this, 'addCheckoutFields'], 16);
        add_action('woocommerce_after_checkout_validation', [$this, 'validateFields'], 10, 2);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'saveToOrder'], 16);
        add_action('woocommerce_checkout_update_order_review', [$this, 'saveToSession']);
        add_action('woocommerce_review_order_after_cart_contents', [$this, 'renderReviewSummary'], 11);
        add_action('alttag_registrations_accommodation_review_inner', [$this, 'renderInsideAccommodationReview']);

        // Auto-registration on order complete
        add_action('woocommerce_order_status_completed', [$this, 'autoRegisterCompanion'], 25);

        // Thank you page
        add_action('alttag_registrations_checkout_after_download_links', [$this, 'renderThankYouNotice']);

        // Participant data
        add_action('alttag_registrations_participant_create', [$this, 'saveToParticipant'], 10, 2);
        add_filter('alttag_registrations_meta_fields', [$this, 'registerMetaFields']);

        // Admin
        add_filter('alttag_registrations_participant_columns', [$this, 'addAdminColumn']);
        add_filter('alttag_registrations_participant_column_content', [$this, 'renderAdminColumn'], 10, 3);
        add_filter('alttag_registrations_meta_box_sections', [$this, 'addMetaBoxFields']);

        // Email & Ticket
        add_action('alttag_registrations_email_after_custom_fields', [$this, 'renderEmailInfo'], 25, 2);
        add_filter('alttag_registrations_pdf_ticket_data', [$this, 'addTicketData'], 11, 3);

        // Verification
        add_filter('alttag_registrations_verification_sections', [$this, 'addVerificationFields']);
        add_filter('alttag_registrations_customer_data', [$this, 'populateVerificationData'], 10, 3);
        add_filter('alttag_registrations_verification_field_label', [$this, 'getVerificationLabel'], 10, 3);

        // Verification: person count controls (count_only mode)
        add_filter('alttag_registrations_show_register_button', [$this, 'filterShowRegisterButton'], 10, 2);
        add_action('alttag_registrations_verification_after_section_content', [$this, 'renderVerificationPersonCount'], 10, 3);
        add_action('alttag_registrations_handle_custom_action', [$this, 'handlePersonCountAction'], 10, 2);

        // Export / Import
        add_filter('alttag_registrations_export_field_order', [$this, 'addExportFields']);
        add_filter('alttag_registrations_import_header_variations', [$this, 'addImportVariations']);

        // Frontend
        add_action('wp_footer', [$this, 'renderCheckoutJs']);
        add_action('wp_head', [$this, 'renderCheckoutCss']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function getCompanionLabel(): string
    {
        return $this->settings->get('companion.companion_label', __('companion', 'alttag-registrations'));
    }

    private function isEmailEnabled(): bool
    {
        return (bool) $this->settings->get('companion.enable_companion_email', true);
    }

    /**
     * Detect if AccommodationModule is active.
     * When active, companion fields appear when accommodation_person_count >= 2.
     * When not active, a standalone checkbox triggers companion fields.
     */
    private function isAccommodationActive(): bool
    {
        $core = Core::getInstance();
        return $core && $core->moduleRegistry && $core->moduleRegistry->isActive('accommodation');
    }

    private function getCartProductId()
    {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return 0;
        }
        foreach (WC()->cart->get_cart() as $item) {
            return $item['product_id'];
        }
        return 0;
    }

    private function isActiveForCart(): bool
    {
        $product_id = $this->getCartProductId();
        return $product_id && $this->isEnabledForProduct($product_id);
    }

    /**
     * Get companion mode for a product.
     *
     * @return string 'full' (default) — companion fields + auto-registration,
     *                'count_only' — person count is saved but no companion
     *                fields are shown and no second participant is created.
     */
    private function getCompanionMode(int $product_id = 0): string
    {
        if (!$product_id) {
            $product_id = $this->getCartProductId();
        }
        if (!$product_id) {
            return 'full';
        }
        $mode = get_post_meta($product_id, '_alttag_companion_mode', true);
        return $mode === 'count_only' ? 'count_only' : 'full';
    }

    private function isCountOnlyMode(int $product_id = 0): bool
    {
        return $this->getCompanionMode($product_id) === 'count_only';
    }

    /**
     * Get custom per-product trigger config.
     *
     * Product meta:
     *   _alttag_companion_trigger_field: name of POST field to check (e.g. 'person_count')
     *   _alttag_companion_trigger_values: comma-separated trigger values (e.g. '2' or '2,3')
     *
     * @return array|null ['field' => string, 'values' => string[]] or null if not configured
     */
    private function getCustomTrigger()
    {
        $product_id = $this->getCartProductId();
        if (!$product_id) {
            return null;
        }
        $field = get_post_meta($product_id, '_alttag_companion_trigger_field', true);
        if (empty($field)) {
            return null;
        }
        $values_raw = get_post_meta($product_id, '_alttag_companion_trigger_values', true);
        $values = array_filter(array_map('trim', explode(',', (string) $values_raw)));
        return [
            'field' => $field,
            'values' => !empty($values) ? $values : ['1'],
        ];
    }

    /**
     * Check if companion is triggered based on POST data.
     *
     * Priority:
     * 1. Custom per-product trigger field (if configured)
     * 2. Accommodation person_count >= 2 (when AccommodationModule active)
     * 3. Standalone register_companion checkbox
     */
    private function isCompanionTriggered(): bool
    {
        $custom = $this->getCustomTrigger();
        if ($custom) {
            $posted = isset($_POST[$custom['field']]) ? $_POST[$custom['field']] : '';
            return in_array((string) $posted, $custom['values'], true);
        }

        if ($this->isAccommodationActive()) {
            $checked = isset($_POST['accommodation']) && $_POST['accommodation'] === '1';
            $count = isset($_POST['accommodation_person_count']) ? $_POST['accommodation_person_count'] : '';
            return $checked && (int) $count >= 2;
        }
        return isset($_POST['register_companion']) && $_POST['register_companion'] === '1';
    }

    // =========================================================================
    // Checkout
    // =========================================================================

    public function addCheckoutFields($fields)
    {
        if (!$this->isActiveForCart()) {
            return $fields;
        }

        // In count_only mode, person_count is a FieldBuilder field —
        // no companion name/email fields needed.
        if ($this->isCountOnlyMode()) {
            return $fields;
        }

        $label = $this->getCompanionLabel();

        // Only show standalone checkbox if neither Accommodation nor custom trigger is configured
        $has_custom_trigger = $this->getCustomTrigger() !== null;
        if (!$this->isAccommodationActive() && !$has_custom_trigger) {
            $fields['billing']['register_companion'] = [
                'type' => 'checkbox',
                'label' => sprintf(__('Register a %s', 'alttag-registrations'), $label),
                'required' => false,
                'class' => ['form-row-wide', 'companion-trigger-field'],
                'priority' => 210,
            ];
        }

        $fields['billing']['companion_first_name'] = [
            'type' => 'text',
            'label' => sprintf(__('First name of %s', 'alttag-registrations'), $label),
            'required' => true,
            'class' => ['form-row-first', 'companion-field'],
            'priority' => 211,
        ];

        $fields['billing']['companion_last_name'] = [
            'type' => 'text',
            'label' => sprintf(__('Last name of %s', 'alttag-registrations'), $label),
            'required' => true,
            'class' => ['form-row-last', 'companion-field'],
            'priority' => 212,
        ];

        $fields['billing']['companion_email'] = [
            'type' => 'email',
            'label' => sprintf(__('Email of %s', 'alttag-registrations'), $label),
            'required' => true,
            'class' => ['form-row-wide', 'companion-field'],
            'priority' => 213,
        ];

        return $fields;
    }

    public function validateFields($data, $errors)
    {
        if (!$this->isCompanionTriggered()) {
            // Remove required errors for companion fields
            $label = $this->getCompanionLabel();
            foreach ($errors->get_error_codes() as $code) {
                foreach ($errors->get_error_messages($code) as $message) {
                    if (strpos($message, $label) !== false) {
                        $errors->remove($code);
                    }
                }
            }
        }
    }

    public function saveToOrder($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $triggered = $this->isCompanionTriggered();
        if (!$this->isAccommodationActive()) {
            $order->update_meta_data('register_companion', $triggered ? '1' : '0');
        }

        if ($triggered) {
            $order->update_meta_data('companion_first_name', sanitize_text_field($_POST['companion_first_name'] ?? ''));
            $order->update_meta_data('companion_last_name', sanitize_text_field($_POST['companion_last_name'] ?? ''));
            $order->update_meta_data('companion_email', sanitize_email($_POST['companion_email'] ?? ''));
        }

        $order->save();
    }

    public function saveToSession($post_data)
    {
        parse_str($post_data, $data);
        $session = WC()->session;
        if (!$session) {
            return;
        }

        if (!$this->isAccommodationActive()) {
            $session->set('register_companion', $data['register_companion'] ?? '0');
        }
        $session->set('companion_first_name', sanitize_text_field($data['companion_first_name'] ?? ''));
        $session->set('companion_last_name', sanitize_text_field($data['companion_last_name'] ?? ''));
        $session->set('companion_email', sanitize_email($data['companion_email'] ?? ''));
    }

    public function renderReviewSummary()
    {
        if ($this->isCountOnlyMode()) {
            return;
        }

        // When accommodation is active, companion info is appended inside the
        // accommodation review box via the alttag_registrations_accommodation_review_inner
        // hook (registered separately) — skip the standalone row to avoid duplication.
        if ($this->isAccommodationActive()) {
            return;
        }

        $session = WC()->session;
        if (!$session) {
            return;
        }

        $companion = $this->getCompanionDisplayInfo($session);
        if (!$companion) {
            return;
        }

        $label = $this->getCompanionLabel();
        ?>
        <tr class="companion-review-row">
            <td colspan="2" style="padding: 4px 0;">
                <span style="font-size: 13px;">
                    <?php echo esc_html(ucfirst($label)) . ': <strong>' . esc_html($companion['name']) . '</strong>'; ?>
                    <?php if ($companion['email']) : ?>
                        (<?php echo esc_html($companion['email']); ?>)
                    <?php endif; ?>
                </span>
            </td>
        </tr>
        <?php
    }

    /**
     * Render companion info inside the accommodation review box.
     */
    public function renderInsideAccommodationReview($session)
    {
        if ($this->isCountOnlyMode()) {
            return;
        }
        $companion = $this->getCompanionDisplayInfo($session);
        if (!$companion) {
            return;
        }
        $label = $this->getCompanionLabel();
        ?>
        <br><span style="font-size: 13px; margin-top: 4px; display: inline-block;">
            <?php echo esc_html(ucfirst($label)) . ': <strong>' . esc_html($companion['name']) . '</strong>'; ?>
            <?php if ($companion['email']) : ?>
                (<?php echo esc_html($companion['email']); ?>)
            <?php endif; ?>
        </span>
        <?php
    }

    private function getCompanionDisplayInfo($session)
    {
        $first = $session->get('companion_first_name');
        $last = $session->get('companion_last_name');
        $name = trim($first . ' ' . $last);
        $email = $session->get('companion_email');
        if (empty($name) && empty($email)) {
            return null;
        }
        return ['name' => $name, 'email' => $email];
    }

    // =========================================================================
    // Auto-registration
    // =========================================================================

    public function autoRegisterCompanion($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // In count_only mode, no second participant is created
        $ctx = \Alttag\Registrations\ctx()->withOrder($order);
        if ($this->isCountOnlyMode((int) $ctx->productId())) {
            return;
        }

        // Prevent duplicate
        if (get_post_meta($order_id, '_companion_registered', true)) {
            return;
        }

        $companion_first = $order->get_meta('companion_first_name');
        $companion_last = $order->get_meta('companion_last_name');
        $companion_email = $order->get_meta('companion_email');

        if ((empty($companion_first) && empty($companion_last)) || empty($companion_email)) {
            return;
        }

        $ctx = \Alttag\Registrations\ctx()->withOrder($order);
        $product_id = $ctx->productId();
        $product_name = $ctx->productName();

        // Generate variable symbol
        $prefix = apply_filters('alttag_registrations_zero_total_variable_symbol_prefix', 'FREE');
        $symbol = $this->generateUniqueVariableSymbol($prefix);

        // Build companion data
        $companion_data = [
            'first_name'      => $companion_first,
            'last_name'       => $companion_last,
            'email'           => $companion_email,
            'phone'           => '',
            'company_name'    => $order->get_billing_company(),
            'variable_symbol' => $symbol,
            'language'        => function_exists('pll_current_language')
                ? pll_current_language('slug')
                : substr(get_locale(), 0, 2),
        ];

        // Copy accommodation status if AccommodationModule stored it
        $accommodation = $order->get_meta('accommodation');
        if ($accommodation === '1') {
            $companion_data['accommodation'] = '1';
        }

        $manager = new \Alttag\Registrations\Participant\Manager();
        $companion_id = $manager->createParticipant($companion_data);

        if (!$companion_id) {
            return;
        }

        // Copy product info
        if ($product_id) {
            update_post_meta($companion_id, 'product_id', $product_id);
            update_post_meta($companion_id, 'product_name', $product_name);
        }

        // Copy session data from order + product config
        $session_date = $order->get_meta('selected_session');
        if (is_array($session_date)) {
            $session_date = reset($session_date);
        }
        if (!empty($session_date)) {
            update_post_meta($companion_id, 'selected_session', $session_date);

            $slot = $order->get_meta('selected_session_slot');
            if (is_string($slot) && !empty($slot)) {
                update_post_meta($companion_id, 'selected_session_slot', $slot);
            }

            // Look up session label + meta from product config
            $pc = $ctx->product();
            if ($pc) {
                $session_config = get_post_meta($product_id, '_session_dates', true);
                if (is_array($session_config)) {
                    foreach ($session_config as $cfg) {
                        if (($cfg['date'] ?? '') !== $session_date) {
                            continue;
                        }
                        if (!empty($cfg['label'])) {
                            update_post_meta($companion_id, 'session_label', $cfg['label']);
                        }
                        if (!empty($cfg['time'])) {
                            update_post_meta($companion_id, 'session_time', $cfg['time']);
                        }
                        if (!empty($cfg['meta']) && is_array($cfg['meta'])) {
                            update_post_meta($companion_id, 'session_meta', $cfg['meta']);
                            foreach ($cfg['meta'] as $k => $v) {
                                update_post_meta($companion_id, 'session_' . $k, $v);
                            }
                        }
                        break;
                    }
                }
            }
        }

        // Store who registered this companion
        $registrant_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $registrant_email = $order->get_billing_email();
        update_post_meta($companion_id, 'registered_by', $registrant_name . ' (' . $registrant_email . ')');
        update_post_meta($companion_id, 'registered_by_email', $registrant_email);
        update_post_meta($companion_id, 'registration_history',
            current_time('mysql') . ': ' . __('Auto-registered as companion', 'alttag-registrations') . ' - ' . $registrant_name
        );

        // Generate QR code and ticket
        $verificationManager = apply_filters('alttag_registrations_verification_manager', null);
        if ($verificationManager) {
            $verificationManager->generateAndSaveTicket($companion_id);
        }

        // Mark done
        update_post_meta($order_id, '_companion_registered', $companion_id);

        // Send email
        if ($this->isEmailEnabled()) {
            $this->sendCompanionEmail($companion_id, $order);
        }
    }

    private function generateUniqueVariableSymbol(string $prefix): string
    {
        global $wpdb;

        do {
            $symbol = $prefix . substr(time(), -6) . str_pad(rand(1, 99), 2, '0', STR_PAD_LEFT);
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE pm.meta_key = 'variable_symbol' AND pm.meta_value = %s
                 AND p.post_type = 'participant' AND p.post_status != 'trash'",
                $symbol
            ));
        } while ($existing);

        return $symbol;
    }

    private function sendCompanionEmail($companion_id, $order)
    {
        $manager = new \Alttag\Registrations\Participant\Manager();
        $details = $manager->getParticipantDetails($companion_id);
        if (!$details) {
            return;
        }

        $companion_email = $details['email'] ?? '';
        if (empty($companion_email)) {
            return;
        }

        $companion_name = trim(($details['first_name'] ?? '') . ' ' . ($details['last_name'] ?? ''));
        $event_name = \Alttag\Registrations\get_event_name();

        // Use session-specific date/location if available
        $session_date = get_post_meta($companion_id, 'selected_session', true);
        if (is_array($session_date)) {
            $session_date = reset($session_date);
        }
        $session_label = get_post_meta($companion_id, 'session_label', true);
        $session_locative = get_post_meta($companion_id, 'session_location_locative', true);
        if (empty($session_locative)) {
            // Same reason as SessionModule::getParticipantSessionData() — the
            // session label is nominative and the sentence below hardcodes
            // "… ktoré sa uskutoční %2$s v %3$s".
            $companion_product_id = (int) get_post_meta($companion_id, 'product_id', true);
            $session_locative = \Alttag\Registrations\Settings::getValue(
                'general.venue_locative',
                $companion_product_id ?: null
            );
        }
        $event_dates = !empty($session_date)
            ? \Alttag\Registrations\format_date($session_date)
            : \Alttag\Registrations\get_event_dates_string();
        $event_location = !empty($session_locative) ? $session_locative : (!empty($session_label) ? $session_label : '');

        $registrant_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $registrant_email = $order->get_billing_email();

        $primary_color = apply_filters('alttag_registrations_email_primary_color', '#323232');
        $accent_color = apply_filters('alttag_registrations_email_accent_color', '#FFFFFF');
        $bg_color = apply_filters('alttag_registrations_email_background_color', '#f5f5f5');
        $p_style = 'display:block;width:100%;margin:0;padding:15px 30px;font-size:15px;line-height:1.7;color:' . $primary_color . ';font-family:Arial,Helvetica,sans-serif;box-sizing:border-box;';
        $detail_style = 'width:100%;box-sizing:border-box;margin:0 0 10px;color:' . $primary_color . ';font-size:14px;font-family:Arial,Helvetica,sans-serif;line-height:1.6;';

        $variable_symbol = $details['variable_symbol'] ?? '';
        $qr_code_url = $details['qr_code_url'] ?? '';

        $subject = sprintf(__('Registration confirmation - %s', 'alttag-registrations'), $event_name);

        ob_start();
        ?>
        <div class="header" style="background:<?php echo $primary_color; ?>;padding:40px 30px;text-align:center;border-radius:12px 12px 0 0;">
            <h1 style="color:<?php echo $accent_color; ?>;font-size:20px;font-weight:600;margin:0;letter-spacing:0.3px;line-height:1.6;">
                <?php echo esc_html__('Registration confirmation', 'alttag-registrations'); ?><br>
                <?php echo esc_html($event_name); ?>
            </h1>
        </div>
        <div style="background-color:#ffffff;padding:20px 0;">
            <p style="<?php echo $p_style; ?>"><?php echo sprintf(
                __('Dear %s,', 'alttag-registrations'), esc_html($companion_name)
            ); ?></p>

            <p style="<?php echo $p_style; ?>"><?php
                if (!empty($event_location)) {
                    echo sprintf(
                        __('You have been registered for %1$s, taking place on %2$s at %3$s.', 'alttag-registrations'),
                        '<strong>' . esc_html($event_name) . '</strong>',
                        esc_html($event_dates),
                        esc_html($event_location)
                    );
                } else {
                    echo sprintf(
                        __('You have been registered for %1$s, taking place on %2$s.', 'alttag-registrations'),
                        '<strong>' . esc_html($event_name) . '</strong>',
                        esc_html($event_dates)
                    );
                }
            ?></p>

            <p style="<?php echo $p_style; ?>"><?php echo sprintf(
                __('Registered by: %1$s (%2$s)', 'alttag-registrations'),
                esc_html($registrant_name),
                '<a href="mailto:' . esc_attr($registrant_email) . '" style="color:' . $primary_color . ';">' . esc_html($registrant_email) . '</a>'
            ); ?></p>

            <?php if (!empty($session_date) || !empty($session_label)) : ?>
            <div style="margin:5px 30px;padding:15px 20px;background:<?php echo $bg_color; ?>;border-left:3px solid <?php echo $primary_color; ?>;border-radius:6px;">
                <?php if (!empty($session_date)) : ?>
                <div style="<?php echo $detail_style; ?>"><strong><?php echo esc_html__('Session', 'alttag-registrations'); ?>:</strong> <?php echo esc_html($event_dates); ?></div>
                <?php endif; ?>
                <?php $session_time = get_post_meta($companion_id, 'session_time', true); if (!empty($session_time)) : ?>
                <div style="<?php echo $detail_style; ?>"><strong><?php echo esc_html__('Time', 'alttag-registrations'); ?>:</strong> <?php echo esc_html($session_time); ?></div>
                <?php endif; ?>
                <?php $slot = get_post_meta($companion_id, 'selected_session_slot', true); if (is_string($slot) && !empty($slot)) : ?>
                <div style="<?php echo $detail_style; ?>"><strong><?php echo esc_html__('Time slot', 'alttag-registrations'); ?>:</strong> <?php echo esc_html($slot); ?></div>
                <?php endif; ?>
                <?php
                $comp_address = get_post_meta($companion_id, 'session_address', true);
                $comp_location = $session_label . (!empty($comp_address) ? ', ' . $comp_address : '');
                if (!empty($comp_location)) : ?>
                <div style="<?php echo $detail_style; ?>"><strong><?php echo esc_html__('Location', 'alttag-registrations'); ?>:</strong> <?php echo esc_html($comp_location); ?></div>
                <?php endif; ?>
                <?php
                // Accommodation info copied from main order
                if (get_post_meta($companion_id, 'accommodation', true) === '1') :
                    $accommodation_label = $this->settings->get('accommodation.accommodation_label', __('Hotel accommodation', 'alttag-registrations'));
                    $paid_by_label = $this->settings->get('accommodation.accommodation_organizer_paid', '');
                ?>
                <div style="<?php echo $detail_style; ?>">
                    <strong><?php echo esc_html($accommodation_label); ?>:</strong>
                    <?php echo esc_html__('Yes', 'alttag-registrations'); ?>
                    <?php if ($paid_by_label) : ?>
                        <span style="color:#666;"> (<?php echo esc_html($paid_by_label); ?>)</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($qr_code_url)) : ?>
            <div class="qr-code" style="width:100%;box-sizing:border-box;padding:10px 30px 30px;text-align:center;margin:0;">
                <p style="color:<?php echo $primary_color; ?>;font-family:Arial,Helvetica,sans-serif;font-size:16px;margin:0 0 20px;padding:0;line-height:1.7;font-weight:600;">
                    <?php echo esc_html__('Your ticket with QR code:', 'alttag-registrations'); ?>
                </p>
                <img src="<?php echo esc_url($qr_code_url); ?>" alt="QR" style="border:4px solid <?php echo $primary_color; ?>;padding:15px;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.15);max-width:250px;margin:0 auto 20px;display:block;">
            </div>
            <?php endif; ?>

            <p style="<?php echo $p_style; ?>"><?php echo esc_html__('We look forward to seeing you!', 'alttag-registrations'); ?></p>
        </div>
        <div class="footer" style="width:100%;background:<?php echo $primary_color; ?>;color:#fff;padding:25px 30px;text-align:center;border-radius:0 0 12px 12px;box-sizing:border-box;">
            <?php
            $footer = apply_filters('alttag_registrations_footer_info_text', '');
            if ($footer) {
                echo '<p style="display:block;width:100%;color:#fff;margin:0 0 15px;font-size:14px;padding:0;font-family:Arial,Helvetica,sans-serif;line-height:1.6;">' . $footer . '</p>';
            }
            $org = \Alttag\Registrations\get_event_organization_team_name();
            if ($org) {
                echo '<p style="display:block;width:100%;color:#fff;margin:0;font-size:14px;padding:0;font-family:Arial,Helvetica,sans-serif;line-height:1.6;"><b style="color:' . $accent_color . ';font-weight:700;font-size:17px;">' . esc_html($org) . '</b></p>';
            }
            ?>
        </div>
        <?php
        $content = ob_get_clean();

        if (class_exists('\Alttag\Registrations\EmailWrapper')) {
            $content = \Alttag\Registrations\EmailWrapper::convertToTableLayout($content);
            $content = \Alttag\Registrations\EmailWrapper::wrap($content, $subject);
        }

        $attachments = \Alttag\Registrations\Participant\Manager::prepareEmailAttachments($details, true, false);

        wp_mail($companion_email, $subject, $content, ['Content-Type: text/html; charset=UTF-8'], $attachments);
    }

    // =========================================================================
    // Participant
    // =========================================================================

    public function renderThankYouNotice($order)
    {
        if (!$order) {
            return;
        }

        $ctx = \Alttag\Registrations\ctx()->withOrder($order);
        if ($this->isCountOnlyMode((int) $ctx->productId())) {
            return;
        }

        $first = $order->get_meta('companion_first_name');
        $last = $order->get_meta('companion_last_name');
        $name = trim($first . ' ' . $last);

        if (empty($name)) {
            return;
        }

        $label = ucfirst($this->getCompanionLabel());
        ?>
        <div style="margin: 20px 0; padding: 20px; background-color: #d4edda; border-left: 4px solid #28a745; border-radius: 4px;">
            <p style="margin: 0; color: #155724; font-size: 14px; line-height: 1.6;">
                <?php echo sprintf(
                    __('%1$s %2$s has been automatically registered and will receive an email with a ticket.', 'alttag-registrations'),
                    esc_html($label),
                    '<strong>' . esc_html($name) . '</strong>'
                ); ?>
            </p>
        </div>
        <?php
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

        foreach (['companion_first_name', 'companion_last_name', 'companion_email'] as $key) {
            $value = $order->get_meta($key);
            if ($value !== '') {
                update_post_meta($participant_id, $key, $value);
            }
        }
    }

    public function registerMetaFields($fields)
    {
        $label = $this->getCompanionLabel();
        $fields['companion_first_name'] = [
            'label' => sprintf(__('First name of %s', 'alttag-registrations'), $label),
            'type' => 'text',
        ];
        $fields['companion_last_name'] = [
            'label' => sprintf(__('Last name of %s', 'alttag-registrations'), $label),
            'type' => 'text',
        ];
        $fields['companion_email'] = [
            'label' => sprintf(__('Email of %s', 'alttag-registrations'), $label),
            'type' => 'email',
        ];
        $fields['registered_by'] = [
            'label' => __('Registered by', 'alttag-registrations'),
            'type' => 'text',
            'readonly' => true,
        ];
        $fields['registered_by_email'] = [
            'label' => __('Registered by email', 'alttag-registrations'),
            'type' => 'email',
            'readonly' => true,
        ];
        return $fields;
    }

    // =========================================================================
    // Admin
    // =========================================================================

    public function addAdminColumn($columns)
    {
        $new = [];
        foreach ($columns as $key => $col_label) {
            if ($key === 'actions') {
                $new['registered_by'] = __('Registered by', 'alttag-registrations');
            }
            $new[$key] = $col_label;
        }
        return $new;
    }

    public function renderAdminColumn($content, $column, $post_id)
    {
        if ($column !== 'registered_by') {
            return $content;
        }

        $by = get_post_meta($post_id, 'registered_by', true);
        return $by ? esc_html($by) : '—';
    }

    public function addMetaBoxFields($sections)
    {
        if (!isset($sections['registration']['fields'])) {
            $sections['registration']['fields'] = [];
        }
        $sections['registration']['fields'][] = 'companion_first_name';
        $sections['registration']['fields'][] = 'companion_last_name';
        $sections['registration']['fields'][] = 'companion_email';
        $sections['registration']['fields'][] = 'registered_by';
        $sections['registration']['fields'][] = 'registered_by_email';
        return $sections;
    }

    // =========================================================================
    // Email
    // =========================================================================

    public function renderEmailInfo($order, $participant_id = null)
    {
        if (!$order) {
            return;
        }

        $first = $order->get_meta('companion_first_name');
        $last = $order->get_meta('companion_last_name');
        $name = trim($first . ' ' . $last);
        $email = $order->get_meta('companion_email');

        if (empty($name) && empty($email)) {
            return;
        }

        $primary_color = apply_filters('alttag_registrations_email_primary_color', '#323232');
        $style = 'width:100%;box-sizing:border-box;margin:0 0 10px;color:' . $primary_color
            . ';font-size:14px;font-family:Arial,Helvetica,sans-serif;line-height:1.6;';
        $label = ucfirst($this->getCompanionLabel());

        ?>
        <div style="<?php echo $style; ?>">
            <?php echo esc_html($label) . ': ' . esc_html($name); ?>
        </div>
        <?php if ($email) : ?>
        <div style="<?php echo $style; ?>">
            <?php echo esc_html(sprintf(__('Email of %s', 'alttag-registrations'), $this->getCompanionLabel())) . ': ' . esc_html($email); ?>
        </div>
        <?php endif; ?>
        <div style="width:100%;margin:15px 0 0;padding:15px;background-color:#d4edda;box-sizing:border-box;border-left:4px solid #28a745;font-size:14px;font-family:Arial,Helvetica,sans-serif;line-height:1.6;">
            <span style="color:#155724;">
                <?php echo sprintf(
                    __('%s has been automatically registered and will receive an email with a ticket.', 'alttag-registrations'),
                    '<strong>' . esc_html($name) . '</strong>'
                ); ?>
            </span>
        </div>
        <?php
    }

    // =========================================================================
    // Ticket
    // =========================================================================

    public function addTicketData($pdf_data, $data, $variable_symbol)
    {
        $participant_id = $data['id'] ?? null;
        if (!$participant_id) {
            return $pdf_data;
        }

        $first = get_post_meta($participant_id, 'companion_first_name', true);
        $last = get_post_meta($participant_id, 'companion_last_name', true);
        $name = trim($first . ' ' . $last);

        if (!empty($name)) {
            $label = ucfirst($this->getCompanionLabel());
            $display = $name;
            $email = get_post_meta($participant_id, 'companion_email', true);
            if ($email) {
                $display .= ' (' . $email . ')';
            }
            $pdf_data['second_column'][] = [
                'label' => $label,
                'value' => $display,
            ];
        }

        return $pdf_data;
    }

    // =========================================================================
    // Verification
    // =========================================================================

    public function addVerificationFields($sections)
    {
        if (isset($sections['registration']['fields'])) {
            $sections['registration']['fields'][] = 'companion_first_name';
            $sections['registration']['fields'][] = 'companion_last_name';
            $sections['registration']['fields'][] = 'companion_email';
            $sections['registration']['fields'][] = 'registered_by';
            $sections['registration']['fields'][] = 'registered_by_email';
        }
        return $sections;
    }

    public function populateVerificationData($data, $participant_id, $participant)
    {
        foreach (['companion_first_name', 'companion_last_name', 'companion_email', 'registered_by', 'registered_by_email'] as $key) {
            $data[$key] = get_post_meta($participant_id, $key, true);
        }
        return $data;
    }

    public function getVerificationLabel($label, $field_id, $data)
    {
        $companion_label = $this->getCompanionLabel();
        $map = [
            'companion_first_name' => sprintf(__('First name of %s', 'alttag-registrations'), $companion_label),
            'companion_last_name' => sprintf(__('Last name of %s', 'alttag-registrations'), $companion_label),
            'companion_email' => sprintf(__('Email of %s', 'alttag-registrations'), $companion_label),
            'registered_by' => __('Registered by', 'alttag-registrations'),
            'registered_by_email' => __('Registered by email', 'alttag-registrations'),
        ];
        return $map[$field_id] ?? $label;
    }

    // =========================================================================
    // Verification: person count controls (count_only mode)
    // =========================================================================

    /**
     * Hide default register/cancel button when count_only mode with person_count > 1.
     * The per-person UI rendered by renderVerificationPersonCount replaces it.
     */
    public function filterShowRegisterButton($show, $customerData)
    {
        $participant_id = $customerData['id'] ?? null;
        if (!$participant_id) {
            return $show;
        }
        $product_id = (int) get_post_meta($participant_id, 'product_id', true);
        if (!$product_id || !$this->isCountOnlyMode($product_id)) {
            return $show;
        }
        $person_count = (int) ($customerData['person_count'] ?? 1);
        if ($person_count > 1) {
            return false;
        }
        return $show;
    }

    /**
     * Render per-person check-in controls in the verification status section.
     */
    public function renderVerificationPersonCount($section_id, $section, $customerData)
    {
        if ($section_id !== 'status') {
            return;
        }
        $participant_id = $customerData['id'] ?? null;
        if (!$participant_id) {
            return;
        }
        $product_id = (int) get_post_meta($participant_id, 'product_id', true);
        if (!$product_id || !$this->isCountOnlyMode($product_id)) {
            return;
        }
        $total = (int) ($customerData['person_count'] ?? 1);
        if ($total <= 1) {
            return;
        }

        $verified = (int) get_post_meta($participant_id, 'verified_person_count', true);
        $vs = esc_attr($customerData['variable_symbol']);
        $color = $verified >= $total ? 'green' : ($verified > 0 ? 'orange' : '#999');

        echo '<div style="margin-top: 16px;">';
        echo '<table style="width: 100%; border-collapse: collapse;">';
        echo '<tr style="background: #f9f9f9;">';
        echo '<th style="text-align: left; padding: 8px; border-bottom: 2px solid #ddd;">' . esc_html__('Persons', 'alttag-registrations') . '</th>';
        echo '<th style="text-align: left; padding: 8px; border-bottom: 2px solid #ddd;">' . esc_html__('Status', 'alttag-registrations') . '</th>';
        echo '<th style="text-align: left; padding: 8px; border-bottom: 2px solid #ddd;">' . esc_html__('Actions', 'alttag-registrations') . '</th>';
        echo '</tr>';
        echo '<tr>';
        echo '<td style="padding: 8px; border-bottom: 1px solid #eee;">';
        echo '<span style="color: ' . esc_attr($color) . '; font-weight: 600;">' . $verified . '/' . $total . '</span>';
        echo '</td>';
        echo '<td style="padding: 8px; border-bottom: 1px solid #eee;">';
        if ($verified >= $total) {
            echo '<span style="color: green;">OK ' . esc_html__('Registered', 'alttag-registrations') . '</span>';
        } elseif ($verified > 0) {
            echo '<span style="color: orange;">' . esc_html__('Partial', 'alttag-registrations') . '</span>';
        } else {
            echo '<span style="color: #999;">- ' . esc_html__('Pending', 'alttag-registrations') . '</span>';
        }
        echo '</td>';
        echo '<td style="padding: 8px; border-bottom: 1px solid #eee;"><div class="verify-actions">';

        // +1
        if ($verified < $total) {
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field('registration_action', 'registration_nonce');
            echo '<input type="hidden" name="variable_symbol" value="' . $vs . '">';
            echo '<button type="submit" name="action" value="person_count_checkin" class="verify-btn verify-btn--register verify-btn--sm" title="' . esc_attr__('Add one person', 'alttag-registrations') . '">+1</button>';
            echo '</form>';
        }
        // -1
        if ($verified > 0) {
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field('registration_action', 'registration_nonce');
            echo '<input type="hidden" name="variable_symbol" value="' . $vs . '">';
            echo '<button type="submit" name="action" value="person_count_checkout" class="verify-btn verify-btn--cancel verify-btn--sm" title="' . esc_attr__('Remove one person', 'alttag-registrations') . '">-1</button>';
            echo '</form>';
        }
        // All
        if ($verified < $total) {
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field('registration_action', 'registration_nonce');
            echo '<input type="hidden" name="variable_symbol" value="' . $vs . '">';
            echo '<button type="submit" name="action" value="person_count_checkin_all" class="verify-btn verify-btn--register verify-btn--sm">' . esc_html__('All', 'alttag-registrations') . '</button>';
            echo '</form>';
        }
        // Cancel all
        if ($verified > 0) {
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field('registration_action', 'registration_nonce');
            echo '<input type="hidden" name="variable_symbol" value="' . $vs . '">';
            echo '<button type="submit" name="action" value="person_count_checkout_all" class="verify-btn verify-btn--cancel verify-btn--sm">' . esc_html__('Cancel All', 'alttag-registrations') . '</button>';
            echo '</form>';
        }

        echo '</div></td></tr></table></div>';
    }

    /**
     * Handle person count check-in/checkout actions from verification page.
     */
    public function handlePersonCountAction($variable_symbol, $action)
    {
        if (strpos($action, 'person_count_') !== 0) {
            return;
        }

        $participant = \Alttag\Registrations\get_participant_by_variable_symbol($variable_symbol);
        if (!$participant) {
            return;
        }
        $pid = $participant->ID;
        $total = (int) get_post_meta($pid, 'person_count', true);
        if ($total <= 1) {
            return;
        }
        $verified = (int) get_post_meta($pid, 'verified_person_count', true);

        if ($action === 'person_count_checkin') {
            $verified = min($verified + 1, $total);
        } elseif ($action === 'person_count_checkout') {
            $verified = max($verified - 1, 0);
        } elseif ($action === 'person_count_checkin_all') {
            $verified = $total;
        } elseif ($action === 'person_count_checkout_all') {
            $verified = 0;
        } else {
            return;
        }

        update_post_meta($pid, 'verified_person_count', $verified);

        // Update registration status
        $state = \Alttag\Registrations\ParticipantState::get($pid);
        if ($state) {
            if ($verified >= $total) {
                $state->setMeta('registration_status', 'confirmed');
            } elseif ($verified === 0) {
                $state->setMeta('registration_status', 'pending');
            }
        }

        // History
        $history = get_post_meta($pid, 'registration_history', true);
        $entry = current_time('Y-m-d H:i') . ' — ' . sprintf(
            __('Person count: %d/%d', 'alttag-registrations'),
            $verified,
            $total
        );
        update_post_meta($pid, 'registration_history', $history ? $history . "\n" . $entry : $entry);
    }

    // =========================================================================
    // Export / Import
    // =========================================================================

    public function addExportFields($fields)
    {
        $fields[] = 'companion_first_name';
        $fields[] = 'companion_last_name';
        $fields[] = 'companion_email';
        $fields[] = 'registered_by';
        $fields[] = 'registered_by_email';
        return $fields;
    }

    public function addImportVariations($variations)
    {
        $variations['companion first name'] = 'companion_first_name';
        $variations['meno kolegu'] = 'companion_first_name';
        $variations['meno kolegu/kolegyne'] = 'companion_first_name';
        $variations['companion last name'] = 'companion_last_name';
        $variations['priezvisko kolegu'] = 'companion_last_name';
        $variations['priezvisko kolegu/kolegyne'] = 'companion_last_name';
        $variations['companion email'] = 'companion_email';
        $variations['email kolegu'] = 'companion_email';
        $variations['email kolegu/kolegyne'] = 'companion_email';
        $variations['registered by'] = 'registered_by';
        $variations['zaregistroval/a'] = 'registered_by';
        $variations['zaregistroval'] = 'registered_by';
        $variations['registered by email'] = 'registered_by_email';
        $variations['email registrujúceho'] = 'registered_by_email';
        $variations['email registrujuceho'] = 'registered_by_email';
        return $variations;
    }

    // =========================================================================
    // Frontend JS/CSS
    // =========================================================================

    public function renderCheckoutJs()
    {
        if (!function_exists('is_checkout') || !is_checkout() || !$this->isActiveForCart()) {
            return;
        }

        // In count_only mode, no companion JS needed
        if ($this->isCountOnlyMode()) {
            return;
        }

        $accomActive = $this->isAccommodationActive();
        $custom = $this->getCustomTrigger();
        ?>
        <script type="text/javascript">
        jQuery(function($) {
            var customTrigger = <?php echo wp_json_encode($custom); ?>;

            function toggleCompanionFields() {
                var show = false;
                if (customTrigger) {
                    var val = String($('[name="' + customTrigger.field + '"]').val() || '');
                    show = customTrigger.values.indexOf(val) !== -1;
                } else <?php if ($accomActive) : ?> {
                    var accomChecked = $('#accommodation').is(':checked');
                    var personCount = $('#accommodation_person_count').val();
                    show = accomChecked && parseInt(personCount) >= 2;
                } <?php else : ?> {
                    show = $('#register_companion').is(':checked');
                } <?php endif; ?>

                var $fields = $('.companion-field');
                if (show) {
                    $fields.slideDown(200);
                } else {
                    $fields.slideUp(200);
                    $('#companion_first_name, #companion_last_name, #companion_email').val('');
                }
            }

            toggleCompanionFields();

            if (customTrigger) {
                $(document).on('change', '[name="' + customTrigger.field + '"]', function() {
                    toggleCompanionFields();
                    $('body').trigger('update_checkout');
                });
            } else <?php if ($accomActive) : ?> {
                $(document).on('change', '#accommodation, #accommodation_person_count', function() {
                    toggleCompanionFields();
                    $('body').trigger('update_checkout');
                });
            } <?php else : ?> {
                $(document).on('change', '#register_companion', function() {
                    toggleCompanionFields();
                    $('body').trigger('update_checkout');
                });
            } <?php endif; ?>

            var companionTimer = null;
            $(document).on('input', '#companion_first_name, #companion_last_name, #companion_email', function() {
                clearTimeout(companionTimer);
                companionTimer = setTimeout(function() {
                    $('body').trigger('update_checkout');
                }, 500);
            });

            $(document.body).on('updated_checkout', function() {
                toggleCompanionFields();
            });
        });
        </script>
        <?php
    }

    public function renderCheckoutCss()
    {
        if (!function_exists('is_checkout') || !is_checkout() || !$this->isActiveForCart() || $this->isCountOnlyMode()) {
            return;
        }
        ?>
        <style>
            .companion-field { display: none; }
        </style>
        <?php
    }
}
