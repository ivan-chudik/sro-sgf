<?php

namespace Alttag\Registrations\Module;

use Alttag\Registrations\Core;

if (!defined('ABSPATH')) {
    exit;
}

class AccommodationModule extends AbstractModule
{
    public function getId(): string
    {
        return 'accommodation';
    }

    public function getName(): string
    {
        return __('Accommodation', 'alttag-registrations');
    }

    public function getDescription(): string
    {
        return __('Add accommodation request with person count to checkout, emails, tickets, and admin.', 'alttag-registrations');
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
            'accommodation' => [
                'accommodation_label' => [
                    'label' => __('Accommodation Label', 'alttag-registrations'),
                    'type' => 'text',
                    'default' => __('Hotel accommodation', 'alttag-registrations'),
                    'description' => __('Label shown on checkout, emails, and tickets', 'alttag-registrations'),
                ],
                'accommodation_organizer_paid' => [
                    'label' => __('Organizer Paid Label', 'alttag-registrations'),
                    'type' => 'text',
                    'default' => '',
                    'description' => __('Optional label like "paid by organizer". Leave empty to hide.', 'alttag-registrations'),
                ],
                'accommodation_person_count_max' => [
                    'label' => __('Max persons', 'alttag-registrations'),
                    'type' => 'number',
                    'default' => '2',
                    'description' => __('Maximum persons per accommodation (creates select 1..N)', 'alttag-registrations'),
                ],
            ],
        ];
    }

    public function registerHooks(): void
    {
        // Checkout fields
        add_filter('woocommerce_checkout_fields', [$this, 'addCheckoutFields'], 15);
        add_action('woocommerce_after_checkout_validation', [$this, 'validateFields'], 10, 2);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'saveToOrder'], 15);
        add_action('woocommerce_checkout_update_order_review', [$this, 'saveToSession']);
        add_action('woocommerce_review_order_after_cart_contents', [$this, 'renderReviewSummary']);

        // Participant
        add_action('alttag_registrations_participant_create', [$this, 'saveToParticipant'], 10, 2);
        add_filter('alttag_registrations_meta_fields', [$this, 'registerMetaFields']);

        // Admin
        add_filter('alttag_registrations_participant_columns', [$this, 'addAdminColumn']);
        add_filter('alttag_registrations_participant_column_content', [$this, 'renderAdminColumn'], 10, 3);
        add_action('alttag_registrations_participant_before_filters', [$this, 'renderAdminFilter']);
        add_filter('alttag_registrations_participant_filters_query', [$this, 'applyAdminFilter']);
        add_filter('alttag_registrations_meta_box_sections', [$this, 'addMetaBoxFields']);

        // Email & Ticket
        add_action('alttag_registrations_email_after_custom_fields', [$this, 'renderEmailInfo'], 10, 2);
        add_filter('alttag_registrations_pdf_ticket_data', [$this, 'addTicketData'], 10, 3);
        add_filter('alttag_registrations_ticket_field_order_options', [$this, 'addTicketFieldOptions']);

        // Verification
        add_filter('alttag_registrations_verification_sections', [$this, 'addVerificationFields']);
        add_filter('alttag_registrations_customer_data', [$this, 'populateVerificationData'], 10, 3);
        add_filter('alttag_registrations_verification_field_value', [$this, 'formatVerificationValue'], 10, 3);
        add_filter('alttag_registrations_verification_field_label', [$this, 'getVerificationLabel'], 10, 3);

        // Export / Import
        add_filter('alttag_registrations_export_field_order', [$this, 'addExportFields']);
        add_filter('alttag_registrations_import_header_variations', [$this, 'addImportVariations']);
        add_filter('alttag_registrations_import_normalize_field', [$this, 'normalizeImportField'], 10, 2);

        // Frontend
        add_action('wp_footer', [$this, 'renderCheckoutJs']);
        add_action('wp_head', [$this, 'renderCheckoutCss']);
    }

    // =========================================================================
    // Settings helpers
    // =========================================================================

    private function getLabel(): string
    {
        return $this->settings->get('accommodation.accommodation_label', __('Hotel accommodation', 'alttag-registrations'));
    }

    private function getOrganizerPaidLabel(): string
    {
        return $this->settings->get('accommodation.accommodation_organizer_paid', '');
    }

    private function getMaxPersons(): int
    {
        return max(1, (int) $this->settings->get('accommodation.accommodation_person_count_max', 2));
    }

    private function getPersonCountOptions(): array
    {
        $max = $this->getMaxPersons();
        $options = ['' => __('Select', 'alttag-registrations')];
        for ($i = 1; $i <= $max; $i++) {
            $options[(string) $i] = sprintf(_n('%d person', '%d persons', $i, 'alttag-registrations'), $i);
        }
        return $options;
    }

    // =========================================================================
    // Checkout
    // =========================================================================

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

    public function addCheckoutFields($fields)
    {
        if (!$this->isActiveForCart()) {
            return $fields;
        }

        $fields['billing']['accommodation'] = [
            'type' => 'checkbox',
            'label' => $this->getLabel(),
            'required' => false,
            'class' => ['form-row-wide', 'accommodation-field'],
            'priority' => 200,
        ];

        $fields['billing']['accommodation_person_count'] = [
            'type' => 'select',
            'label' => __('Number of persons', 'alttag-registrations'),
            'required' => true,
            'class' => ['form-row-wide', 'accommodation-sub-field'],
            'priority' => 201,
            'options' => $this->getPersonCountOptions(),
        ];

        return $fields;
    }

    public function validateFields($data, $errors)
    {
        if (!$this->isActiveForCart()) {
            return;
        }

        $checked = isset($_POST['accommodation']) && $_POST['accommodation'] === '1';
        if (!$checked) {
            // Remove required errors for sub-fields
            foreach ($errors->get_error_codes() as $code) {
                foreach ($errors->get_error_messages($code) as $message) {
                    if (strpos($message, __('Number of persons', 'alttag-registrations')) !== false) {
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

        $checked = isset($_POST['accommodation']) && $_POST['accommodation'] === '1';
        $order->update_meta_data('accommodation', $checked ? '1' : '0');
        $order->update_meta_data('accommodation_person_count', $checked ? sanitize_text_field($_POST['accommodation_person_count'] ?? '') : '');
        $order->save();
    }

    public function saveToSession($post_data)
    {
        parse_str($post_data, $data);
        $session = WC()->session;
        if (!$session) {
            return;
        }
        $session->set('accommodation', isset($data['accommodation']) ? $data['accommodation'] : '0');
        $session->set('accommodation_person_count', isset($data['accommodation_person_count']) ? $data['accommodation_person_count'] : '');
    }

    public function renderReviewSummary()
    {
        $session = WC()->session;
        if (!$session || $session->get('accommodation') !== '1') {
            return;
        }

        $person_count = $session->get('accommodation_person_count');
        $label = $this->getLabel();
        $paid_label = $this->getOrganizerPaidLabel();
        ?>
        <tr class="accommodation-review-row">
            <td colspan="2" style="padding: 12px 0; border-top: 1px solid #e5e5e5;">
                <div style="background-color: #f0f4f8; border-left: 4px solid currentColor; padding: 12px 16px; border-radius: 4px;">
                    <strong style="font-size: 14px;"><?php echo esc_html($label); ?></strong>
                    <?php if ($paid_label) : ?>
                        <span style="color: #666; font-size: 13px; margin-left: 4px;">(<?php echo esc_html($paid_label); ?>)</span>
                    <?php endif; ?>
                    <?php if ($person_count) : ?>
                        <br><span style="font-size: 13px; margin-top: 4px; display: inline-block;">
                            <?php echo esc_html(sprintf(_n('%d person', '%d persons', (int) $person_count, 'alttag-registrations'), (int) $person_count)); ?>
                        </span>
                    <?php endif; ?>
                    <?php
                    /**
                     * Hook to append additional content inside the accommodation review box
                     * (e.g. companion name/email when accommodation triggers companion).
                     */
                    do_action('alttag_registrations_accommodation_review_inner', $session);
                    ?>
                </div>
            </td>
        </tr>
        <?php
    }

    // =========================================================================
    // Participant
    // =========================================================================

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

        $value = $order->get_meta('accommodation');
        if ($value !== '') {
            update_post_meta($participant_id, 'accommodation', $value);
        }
        $count = $order->get_meta('accommodation_person_count');
        if ($count !== '') {
            update_post_meta($participant_id, 'accommodation_person_count', $count);
        }
    }

    public function registerMetaFields($fields)
    {
        $fields['accommodation'] = [
            'label' => __('Accommodation', 'alttag-registrations'),
            'admin_label' => $this->getLabel(),
            'type' => 'checkbox',
        ];
        $fields['accommodation_person_count'] = [
            'label' => __('Accommodation Person Count', 'alttag-registrations'),
            'admin_label' => __('Number of persons (accommodation)', 'alttag-registrations'),
            'type' => 'text',
        ];
        return $fields;
    }

    // =========================================================================
    // Admin
    // =========================================================================

    public function addAdminColumn($columns)
    {
        $new = [];
        foreach ($columns as $key => $label) {
            if ($key === 'actions') {
                $new['accommodation'] = $this->getLabel();
            }
            $new[$key] = $label;
        }
        return $new;
    }

    public function renderAdminColumn($content, $column, $post_id)
    {
        if ($column !== 'accommodation') {
            return $content;
        }

        $has = get_post_meta($post_id, 'accommodation', true);
        if ($has !== '1') {
            return __('No', 'alttag-registrations');
        }

        // Guest participant (auto-registered companion) — show who registered them
        $registered_by = get_post_meta($post_id, 'registered_by', true);
        if (!empty($registered_by)) {
            return __('Yes', 'alttag-registrations') . '<br><small>' . esc_html($registered_by) . '</small>';
        }

        // Regular participant — show person count and companion details
        $count = get_post_meta($post_id, 'accommodation_person_count', true);
        $n = max(1, (int) $count);
        $persons = sprintf(_n('%d pers.', '%d pers.', $n, 'alttag-registrations'), $n);
        $display = __('Yes', 'alttag-registrations') . ' (' . $persons . ')';

        if ((int) $count >= 2) {
            $companion_first = get_post_meta($post_id, 'companion_first_name', true);
            $companion_last = get_post_meta($post_id, 'companion_last_name', true);
            $companion_name = trim($companion_first . ' ' . $companion_last);
            $companion_email = get_post_meta($post_id, 'companion_email', true);
            if (!empty($companion_name)) {
                $display .= '<br><small>' . esc_html($companion_name) . '</small>';
            }
            if (!empty($companion_email)) {
                $display .= '<br><small>' . esc_html($companion_email) . '</small>';
            }
        }

        return $display;
    }

    public function renderAdminFilter()
    {
        $current = isset($_GET['filter_accommodation']) ? sanitize_text_field($_GET['filter_accommodation']) : '';
        echo '<select name="filter_accommodation">';
        echo '<option value="">' . esc_html(sprintf(__('All (%s)', 'alttag-registrations'), $this->getLabel())) . '</option>';
        echo '<option value="1"' . selected($current, '1', false) . '>' . esc_html(__('With accommodation', 'alttag-registrations')) . '</option>';
        echo '<option value="0"' . selected($current, '0', false) . '>' . esc_html(__('Without accommodation', 'alttag-registrations')) . '</option>';
        echo '</select>';
    }

    public function applyAdminFilter($meta_query)
    {
        if (!isset($_GET['filter_accommodation']) || $_GET['filter_accommodation'] === '') {
            return $meta_query;
        }

        if ($_GET['filter_accommodation'] === '1') {
            $meta_query[] = ['key' => 'accommodation', 'value' => '1'];
        } else {
            $meta_query[] = [
                'relation' => 'OR',
                ['key' => 'accommodation', 'value' => '1', 'compare' => '!='],
                ['key' => 'accommodation', 'compare' => 'NOT EXISTS'],
            ];
        }
        return $meta_query;
    }

    public function addMetaBoxFields($sections)
    {
        if (!isset($sections['personal']['fields'])) {
            $sections['personal']['fields'] = [];
        }
        $sections['personal']['fields'][] = 'accommodation';
        $sections['personal']['fields'][] = 'accommodation_person_count';
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

        if ($order->get_meta('accommodation') !== '1') {
            return;
        }

        $primary_color = apply_filters('alttag_registrations_email_primary_color', '#323232');
        $style = 'width:100%;box-sizing:border-box;margin:0 0 10px;color:' . $primary_color
            . ';font-size:14px;font-family:Arial,Helvetica,sans-serif;line-height:1.6;';

        $count = $order->get_meta('accommodation_person_count');
        $paid_label = $this->getOrganizerPaidLabel();
        ?>
        <div style="<?php echo $style; ?>">
            <?php echo esc_html($this->getLabel()) . ': ' . __('Yes', 'alttag-registrations'); ?>
            <?php if ($paid_label) : ?>
                (<strong><?php echo esc_html($paid_label); ?></strong>)
            <?php endif; ?>
        </div>
        <?php if ($count) : ?>
        <div style="<?php echo $style; ?>">
            <?php echo esc_html(__('Number of persons', 'alttag-registrations') . ': ' .
                sprintf(_n('%d person', '%d persons', (int) $count, 'alttag-registrations'), (int) $count)); ?>
        </div>
        <?php endif;
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

        $has = get_post_meta($participant_id, 'accommodation', true);
        if ($has !== '1') {
            return $pdf_data;
        }

        $pdf_data['second_column'][] = [
            'key' => 'accommodation',
            'label' => $this->getLabel(),
            'value' => __('Yes', 'alttag-registrations'),
        ];

        $count = get_post_meta($participant_id, 'accommodation_person_count', true);
        if (!empty($count)) {
            $pdf_data['second_column'][] = [
                'key' => 'accommodation_person_count',
                'label' => __('Number of persons', 'alttag-registrations'),
                'value' => sprintf(_n('%d person', '%d persons', (int) $count, 'alttag-registrations'), (int) $count),
            ];
        }

        return $pdf_data;
    }

    /**
     * Expose accommodation virtual fields in the Ticket Designer reorder UI.
     */
    public function addTicketFieldOptions($options)
    {
        $accommodation_fields = [
            'accommodation' => $this->getLabel(),
            'accommodation_person_count' => __('Accommodation: Number of persons', 'alttag-registrations'),
        ];
        return $accommodation_fields + $options;
    }

    // =========================================================================
    // Verification
    // =========================================================================

    public function addVerificationFields($sections)
    {
        if (isset($sections['personal']['fields'])) {
            $sections['personal']['fields'][] = 'accommodation';
            $sections['personal']['fields'][] = 'accommodation_person_count';
        }
        return $sections;
    }

    public function populateVerificationData($data, $participant_id, $participant)
    {
        $data['accommodation'] = get_post_meta($participant_id, 'accommodation', true) ?: '0';
        $data['accommodation_person_count'] = get_post_meta($participant_id, 'accommodation_person_count', true);
        return $data;
    }

    public function formatVerificationValue($value, $field_id, $data)
    {
        if ($field_id === 'accommodation') {
            return $value === '1' ? __('Yes', 'alttag-registrations') : __('No', 'alttag-registrations');
        }
        if ($field_id === 'accommodation_person_count' && $value) {
            return sprintf(_n('%d person', '%d persons', (int) $value, 'alttag-registrations'), (int) $value);
        }
        return $value;
    }

    public function getVerificationLabel($label, $field_id, $data)
    {
        if ($field_id === 'accommodation') {
            return $this->getLabel();
        }
        if ($field_id === 'accommodation_person_count') {
            return __('Number of persons', 'alttag-registrations');
        }
        return $label;
    }

    // =========================================================================
    // Export / Import
    // =========================================================================

    public function addExportFields($fields)
    {
        $fields[] = 'accommodation';
        $fields[] = 'accommodation_person_count';
        return $fields;
    }

    public function addImportVariations($variations)
    {
        $variations['accommodation'] = 'accommodation';
        $variations['ubytovanie'] = 'accommodation';
        $variations['ubytovanie v hoteli'] = 'accommodation';
        $variations['hotel'] = 'accommodation';
        $variations['hotel accommodation'] = 'accommodation';
        $variations['počet osôb (hotel)'] = 'accommodation_person_count';
        $variations['pocet osob (hotel)'] = 'accommodation_person_count';
        $variations['počet osôb'] = 'accommodation_person_count';
        $variations['accommodation person count'] = 'accommodation_person_count';
        return $variations;
    }

    public function normalizeImportField($value, $field)
    {
        if ($field === 'accommodation') {
            $true_values = ['1', 'yes', 'true', 'áno', 'ano'];
            return in_array(mb_strtolower(trim($value), 'UTF-8'), $true_values) ? '1' : '';
        }
        return $value;
    }

    // =========================================================================
    // Frontend JS/CSS
    // =========================================================================

    public function renderCheckoutJs()
    {
        if (!function_exists('is_checkout') || !is_checkout() || !$this->isActiveForCart()) {
            return;
        }
        ?>
        <script type="text/javascript">
        jQuery(function($) {
            function toggleAccommodationFields() {
                var checked = $('#accommodation').is(':checked');
                var $sub = $('.accommodation-sub-field');
                if (checked) {
                    $sub.slideDown(200);
                } else {
                    $sub.slideUp(200);
                    $('#accommodation_person_count').val('');
                }
            }

            toggleAccommodationFields();

            $(document).on('change', '#accommodation', function() {
                toggleAccommodationFields();
                $('body').trigger('update_checkout');
            });

            $(document).on('change', '#accommodation_person_count', function() {
                $('body').trigger('update_checkout');
            });

            $(document.body).on('updated_checkout', function() {
                toggleAccommodationFields();
            });
        });
        </script>
        <?php
    }

    public function renderCheckoutCss()
    {
        if (!function_exists('is_checkout') || !is_checkout() || !$this->isActiveForCart()) {
            return;
        }
        ?>
        <style>
            .accommodation-sub-field { display: none; }
        </style>
        <?php
    }
}
