<?php

// Define sections similar to PostType.php
$sections = [
    'status' => [
        'title' => __('Registration Status', 'alttag-registrations'),
        'fields' => ['registration_status', 'update_date'],
        'show_buttons' => true,
        'show_history' => true,
    ],
    'personal' => [
        'title' => __('Personal Information', 'alttag-registrations'),
        'fields' => ['first_name', 'last_name', 'email', 'phone'],
    ],
    'company' => [
        'title' => __('Company Information', 'alttag-registrations'),
        'fields' => ['company_name', 'business_id', 'tax_id', 'vat_id'],
    ],
    'address' => [
        'title' => __('Address', 'alttag-registrations'),
        'fields' => ['street', 'city', 'zip', 'country'],
    ],
    'registration' => [
        'title' => __('Registration Details', 'alttag-registrations'),
        'fields' => array_merge(
            ['variable_symbol', 'order_id'],
            \Alttag\Registrations\RegistrationContext::current()->isMultilingual() ? ['language'] : [],
            ['order_status', 'registration_status', 'create_date', 'update_date', 'order_comments']
        ),
    ],
    'documents' => [
        'title' => __('Documents', 'alttag-registrations'),
        'fields' => ['invoice_id', 'invoice_url', 'ticket_url'],
    ],
];

// Allow filtering of visible fields on verification page
$hidden_fields = apply_filters('alttag_registrations_verification_hidden_fields', [
    'qr_code_img', 'qr_code_url', 'ticket_file_path'
]);

// Allow filtering of sections
$sections = apply_filters('alttag_registrations_verification_sections', $sections);

/**
 * Design of this page comes from a filterable palette.
 *
 * The defaults below are deliberately neutral (light card, system font) so the
 * plugin does not carry any one project's branding. A project restyles the page
 * from its own customization plugin:
 *
 *   add_filter('alttag_registrations_verification_palette', function ($p) {
 *       $p['surface'] = '#101418';
 *       $p['accent']  = '#c8e65a';
 *       return $p;
 *   });
 *
 * Values are printed as CSS custom properties, so a project never has to fight
 * CSS specificity or load order to change a colour.
 */
function alttag_verification_palette() {
    static $palette = null;

    if ($palette === null) {
        $palette = apply_filters('alttag_registrations_verification_palette', [
            'font' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif",
            'font_label' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif",
            'page_bg' => 'transparent',
            'text' => '#1d2130',
            'heading' => '#1d2130',
            'surface' => '#ffffff',
            'border' => 'rgba(0, 0, 0, .10)',
            'radius' => '12px',
            'shadow' => '0 6px 22px rgba(0, 0, 0, .07)',
            'label' => '#5c6079',
            'label_size' => '11px',
            'label_transform' => 'uppercase',
            'label_spacing' => '1px',
            'title_spacing' => '1.6px',
            'value' => '#1d2130',
            'muted' => '#4a4f63',
            'accent' => '#2f2a7a',
            'accent_hover' => '#413ba0',
            'accent_text' => '#ffffff',
            'accent_soft' => 'rgba(47, 42, 122, .35)',
            'accent_glow' => 'rgba(47, 42, 122, .18)',
            'danger' => '#b32d2e',
            'danger_soft' => 'rgba(179, 45, 46, .10)',
            'button_radius' => '999px',
            'highlight_bg' => 'rgba(47, 42, 122, .06)',
            'highlight_text' => '#2f2a7a',
            'status_success' => 'green',
            'status_pending' => 'orange',
            'status_danger' => 'red',
        ]);
    }

    return $palette;
}

/**
 * Status badge colours, resolved from the palette.
 *
 * @return array{success: string, pending: string, danger: string}
 */
function alttag_verification_status_colors() {
    $palette = alttag_verification_palette();

    return [
        'success' => isset($palette['status_success']) ? $palette['status_success'] : 'green',
        'pending' => isset($palette['status_pending']) ? $palette['status_pending'] : 'orange',
        'danger' => isset($palette['status_danger']) ? $palette['status_danger'] : 'red',
    ];
}

// Function to get translatable field label
function get_field_label($field_id) {
    $labels = [
        'first_name' => __('First Name', 'alttag-registrations'),
        'last_name' => __('Last Name', 'alttag-registrations'),
        'email' => __('Email', 'alttag-registrations'),
        'phone' => __('Phone', 'alttag-registrations'),
        'company_name' => __('Company Name', 'alttag-registrations'),
        'business_id' => __('Business ID', 'alttag-registrations'),
        'tax_id' => __('Tax ID', 'alttag-registrations'),
        'vat_id' => __('VAT ID', 'alttag-registrations'),
        'street' => __('Street', 'alttag-registrations'),
        'city' => __('City', 'alttag-registrations'),
        'zip' => __('ZIP', 'alttag-registrations'),
        'country' => __('Country', 'alttag-registrations'),
        'variable_symbol' => __('Variable Symbol', 'alttag-registrations'),
        'order_id' => __('Order ID', 'alttag-registrations'),
        'order_status' => __('Order Status', 'alttag-registrations'),
        'registration_status' => __('Registration Status', 'alttag-registrations'),
        'create_date' => __('Registration date', 'alttag-registrations'),
        'update_date' => __('Updated at', 'alttag-registrations'),
        'order_comments' => __('Order Comments', 'alttag-registrations'),
        'invoice_id' => __('Invoice ID', 'alttag-registrations'),
        'invoice_url' => __('Invoice', 'alttag-registrations'),
        'ticket_url' => __('Ticket', 'alttag-registrations'),
        'language' => __('Language', 'alttag-registrations'),
        'participant_type' => __('Participant type', 'alttag-registrations'),
        'product_name' => __('Product', 'alttag-registrations'),
        'selected_days_display' => __('Selected days', 'alttag-registrations'),
    ];

    return isset($labels[$field_id]) ? $labels[$field_id] : ucfirst(str_replace('_', ' ', $field_id));
}

// Function to format field value for display
function format_field_value($field_id, $value) {
    switch ($field_id) {
        case 'create_date':
        case 'update_date':
            return !empty($value) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($value)) : '';
        
        case 'registration_status':
            // Badge colours come from the same palette as the rest of the page,
            // so a dark surface can raise the contrast (see $av_palette below).
            $status_colors = alttag_verification_status_colors();
            $status_labels = [
                'confirmed' => '<span style="color: ' . esc_attr($status_colors['success']) . ';">' . __('Registered', 'alttag-registrations') . '</span>',
                'completed' => '<span style="color: ' . esc_attr($status_colors['success']) . ';">' . __('Completed', 'alttag-registrations') . '</span>',
                'partial' => '<span style="color: ' . esc_attr($status_colors['pending']) . ';">' . __('Partial', 'alttag-registrations') . '</span>',
                'cancelled' => '<span style="color: ' . esc_attr($status_colors['danger']) . ';">' . __('Cancelled', 'alttag-registrations') . '</span>',
                'pending' => '<span style="color: ' . esc_attr($status_colors['pending']) . ';">' . __('Pending', 'alttag-registrations') . '</span>',
            ];
            return isset($status_labels[$value]) ? $status_labels[$value] : $value;
            
        case 'country':
            global $woocommerce;
            if (function_exists('WC') && isset(WC()->countries)) {
                $countries = WC()->countries->get_countries();
                return isset($countries[$value]) ? $countries[$value] : $value;
            }
            return $value;

        case 'order_comments':
            return !empty($value) ? nl2br(esc_html($value)) : '';

        case 'language':
            if (\Alttag\Registrations\RegistrationContext::current()->hasPolylang() && !empty($value)) {
                $lang = \PLL()->model->get_language($value);
                if ($lang) {
                    return $lang->name;
                }
            }
            return $value;

        default:
            return $value;
    }
}
?>

<div id="Content">
    <div class="content_wrapper clearfix">
        <div class="sections_group">
            <div class="entry-content">
                <div class="section">
                    <div class="section_wrapper clearfix">
                        <div class="items_group clearfix">
                            <div class="column one column_column">
                                <div class="column_attr clearfix">
                                    <h2 class="title" style="text-align: center; margin-bottom: 50px;"><?php _e('Registration Verification', 'alttag-registrations'); ?></h2>

                                    <?php if (isset($customerData) && !empty($customerData)) : ?>
                                        <?php 
                                        // Display sections
                                        foreach ($sections as $section_id => $section) :
                                            // Skip sections that have no data
                                            $has_data = false;
                                            foreach ($section['fields'] as $field_id) {
                                                if (!in_array($field_id, $hidden_fields) && !empty($customerData[$field_id])) {
                                                    $has_data = true;
                                                    break;
                                                }
                                            }
                                            
                                            // Special case for status section which should always show
                                            if ($section_id === 'status' && isset($customerData['registration_status'])) {
                                                $has_data = true;
                                            }

                                            // Allow filtering to force show specific sections
                                            $has_data = apply_filters('alttag_registrations_verification_section_has_data', $has_data, $section_id, $section, $customerData);

                                            if (!$has_data && $section_id !== 'documents') continue;
                                        ?>
                                        <div class="fancy_box <?php echo esc_attr($section['class'] ?? ''); ?>">
                                            <div class="inside">
                                                <h3 class="title"><?php echo esc_html($section['title']); ?></h3>
                                                
                                                <?php if ($section_id === 'status') : ?>
                                                    <p><strong><?php _e('Current Status', 'alttag-registrations'); ?>:</strong> 
                                                        <?php echo format_field_value('registration_status', $customerData['registration_status']); ?>
                                                    </p>

                                                    <?php if (!empty($customerData['update_date'])) : ?>
                                                        <p><strong><?php _e('Last Update', 'alttag-registrations'); ?>:</strong> 
                                                            <?php echo esc_html(format_field_value('update_date', $customerData['update_date'])); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (isset($section['show_buttons']) && $section['show_buttons']) : ?>
                                                    <form method="post">
                                                        <?php wp_nonce_field('registration_action', 'registration_nonce'); ?>
                                                        <input type="hidden" name="variable_symbol" value="<?php echo esc_attr($customerData['variable_symbol']); ?>">
                                                        
                                                        <?php if ($customerData['registration_status'] !== 'confirmed' && apply_filters('alttag_registrations_show_register_button', true, $customerData)) : ?>
                                                            <?php
                                                            $_person_count = isset($customerData['person_count']) ? (int) $customerData['person_count'] : 1;
                                                            if ($_person_count > 1) {
                                                                $register_label = sprintf(__('Register %d Participants', 'alttag-registrations'), $_person_count);
                                                            } else {
                                                                $register_label = __('Register Participant', 'alttag-registrations');
                                                            }
                                                            ?>
                                                            <button type="submit" name="action" value="register" class="verify-btn verify-btn--register"><?php echo esc_html($register_label); ?></button>
                                                        <?php endif; ?>

                                                        <?php if ($customerData['registration_status'] === 'confirmed') : ?>
                                                            <button type="submit" name="action" value="cancel" class="verify-btn verify-btn--cancel"><?php _e('Cancel Registration', 'alttag-registrations'); ?></button>
                                                        <?php endif; ?>
                                                    </form>
                                                    <?php endif; ?>

                                                    <?php if (isset($section['show_history']) && $section['show_history'] && !empty($customerData['registration_history'])) : ?>
                                                        <h4 style="margin-top: 20px;"><?php _e('History', 'alttag-registrations'); ?>:</h4>
                                                        <table style="width: 100%; border-collapse: collapse;">
                                                            <tr>
                                                                <th style="text-align: left; padding: 5px; border-bottom: 1px solid var(--av-border);"><?php _e('Date', 'alttag-registrations'); ?></th>
                                                                <th style="text-align: left; padding: 5px; border-bottom: 1px solid var(--av-border);"><?php _e('Event', 'alttag-registrations'); ?></th>
                                                            </tr>
                                                            <?php 
                                                            $history_array = explode("\n", $customerData['registration_history']);
                                                            foreach (array_reverse($history_array) as $history_line) : 
                                                                if (preg_match('/\[(.*?)\] (.*)/', $history_line, $matches)) :
                                                                    $timestamp = $matches[1];
                                                                    $event = $matches[2];
                                                            ?>
                                                                <tr>
                                                                    <td style="padding: 5px; border-bottom: 1px solid var(--av-border);">
                                                                        <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($timestamp))); ?>
                                                                    </td>
                                                                    <td style="padding: 5px; border-bottom: 1px solid var(--av-border);">
                                                                        <?php echo esc_html($event); ?>
                                                                    </td>
                                                                </tr>
                                                            <?php 
                                                                endif;
                                                            endforeach; 
                                                            ?>
                                                        </table>
                                                    <?php endif; ?>
                                                <?php elseif ($section_id === 'documents') : ?>
                                                    <ul>
                                                        <?php foreach ($section['fields'] as $field_id) : ?>
                                                            <?php if (!in_array($field_id, $hidden_fields) && !empty($customerData[$field_id])) : ?>
                                                                <?php 
                                                                $field_label = apply_filters('alttag_registrations_verification_field_label', get_field_label($field_id), $field_id, $customerData);
                                                                $field_value = format_field_value($field_id, $customerData[$field_id], []);
                                                                
                                                                // Apply filter to field value before display
                                                                $field_value = apply_filters(
                                                                    'alttag_registrations_verification_field_value',
                                                                    $field_value,
                                                                    $field_id,
                                                                    $customerData
                                                                );
                                                                ?>
                                                                <li>
                                                                    <strong><?php echo esc_html($field_label); ?>:</strong>
                                                                    <span class="verify-value">
                                                                    <?php if ($field_id === 'invoice_url' || $field_id === 'ticket_url') : ?>
                                                                        <a href="<?php echo esc_url($field_value); ?>" target="_blank"><?php _e('View', 'alttag-registrations'); ?></a>
                                                                    <?php else : ?>
                                                                        <?php echo wp_kses_post($field_value); ?>
                                                                    <?php endif; ?>
                                                                    </span>
                                                                </li>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php else : ?>
                                                    <ul>
                                                        <?php foreach ($section['fields'] as $field_id) : ?>
                                                            <?php if (!in_array($field_id, $hidden_fields) && !empty($customerData[$field_id])) : ?>
                                                                <?php 
                                                                $field_label = apply_filters('alttag_registrations_verification_field_label', get_field_label($field_id), $field_id, $customerData);
                                                                $field_value = format_field_value($field_id, $customerData[$field_id], []);
                                                                
                                                                // Apply filter to field value before display
                                                                $field_value = apply_filters(
                                                                    'alttag_registrations_verification_field_value',
                                                                    $field_value,
                                                                    $field_id,
                                                                    $customerData
                                                                );
                                                                ?>
                                                                <li>
                                                                    <strong><?php echo esc_html($field_label); ?>:</strong>
                                                                    <span class="verify-value"><?php echo wp_kses_post(is_string($field_value) ? $field_value : (is_array($field_value) ? implode(', ', $field_value) : '')); ?></span>
                                                                </li>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                        
                                                        <?php if ($section_id === 'personal') : ?>
                                                            <?php 
                                                            /**
                                                             * Hook to add custom event-specific fields to participant details
                                                             * 
                                                             * @param array $customerData The customer data array
                                                             */
                                                            do_action('alttag_registrations_verification_participant_details_fields', $customerData); 
                                                            ?>
                                                        <?php elseif ($section_id === 'registration') : ?>
                                                            <?php
                                                            /**
                                                             * Hook to add custom event-specific fields to registration details
                                                             *
                                                             * @param array $customerData The customer data array
                                                             */
                                                            do_action('alttag_registrations_verification_participant_registration_fields', $customerData);
                                                            ?>
                                                        <?php endif; ?>
                                                    </ul>
                                                <?php endif; ?>
                                                
                                                <?php
                                                /**
                                                 * Hook to add custom content after section
                                                 *
                                                 * @param string $section_id The section ID
                                                 * @param array $section The section configuration
                                                 * @param array $customerData The customer data array
                                                 */
                                                do_action('alttag_registrations_verification_after_section_content', $section_id, $section, $customerData);
                                                ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// A project can put anything in the palette, so strip markup before it reaches
// the <style> block. esc_attr() is wrong here: it would mangle the quotes inside
// the font stacks.
$av_palette = array_map(static function ($value) {
    return is_string($value) ? (string) wp_strip_all_tags($value) : $value;
}, alttag_verification_palette());
?>
<style>
/* Verification page. Colours and fonts come from the palette above, the layout
 * and the spacing are the same for every project. */
.section_wrapper.clearfix {
    --av-font: <?php echo $av_palette['font']; ?>;
    --av-font-label: <?php echo $av_palette['font_label']; ?>;
    --av-text: <?php echo $av_palette['text']; ?>;
    --av-heading: <?php echo $av_palette['heading']; ?>;
    --av-surface: <?php echo $av_palette['surface']; ?>;
    --av-border: <?php echo $av_palette['border']; ?>;
    --av-radius: <?php echo $av_palette['radius']; ?>;
    --av-shadow: <?php echo $av_palette['shadow']; ?>;
    --av-label: <?php echo $av_palette['label']; ?>;
    --av-label-size: <?php echo $av_palette['label_size']; ?>;
    --av-label-transform: <?php echo $av_palette['label_transform']; ?>;
    --av-label-spacing: <?php echo $av_palette['label_spacing']; ?>;
    --av-title-spacing: <?php echo $av_palette['title_spacing']; ?>;
    --av-value: <?php echo $av_palette['value']; ?>;
    --av-muted: <?php echo $av_palette['muted']; ?>;
    --av-accent: <?php echo $av_palette['accent']; ?>;
    --av-accent-hover: <?php echo $av_palette['accent_hover']; ?>;
    --av-accent-text: <?php echo $av_palette['accent_text']; ?>;
    --av-accent-soft: <?php echo $av_palette['accent_soft']; ?>;
    --av-accent-glow: <?php echo $av_palette['accent_glow']; ?>;
    --av-danger: <?php echo $av_palette['danger']; ?>;
    --av-danger-soft: <?php echo $av_palette['danger_soft']; ?>;
    --av-button-radius: <?php echo $av_palette['button_radius']; ?>;
    --av-highlight-bg: <?php echo $av_palette['highlight_bg']; ?>;
    --av-highlight-text: <?php echo $av_palette['highlight_text']; ?>;

    max-width: 960px;
    margin: 0 auto;
    padding: 0 20px;
    position: relative;
    background: <?php echo $av_palette['page_bg']; ?>;
    font-family: var(--av-font);
    color: var(--av-text);
}

.section {
    position: relative;
}

.one.column {
    width: 100%;
}

.column, .columns {
    margin: 0 0 24px;
}

.fancy_box {
    margin-bottom: 20px;
    padding: 22px 26px;
    background: var(--av-surface);
    border: 1px solid var(--av-border);
    border-left: 3px solid var(--av-accent);
    border-radius: var(--av-radius);
    box-shadow: var(--av-shadow);
    color: var(--av-text);
}

.fancy_box .inside {
    padding: 0;
}

.fancy_box h3.title,
.fancy_box .title {
    margin: 0 0 14px 0;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--av-border);
    font-family: var(--av-font-label);
    font-size: 12px;
    letter-spacing: var(--av-title-spacing);
    text-transform: var(--av-label-transform);
    color: var(--av-label);
    font-weight: 500;
}

.fancy_box ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.fancy_box ul li {
    padding: 10px 0;
    border-bottom: 1px solid var(--av-border);
    font-size: 15px;
    line-height: 1.55;
    color: var(--av-muted);
    display: flex;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.fancy_box ul li strong {
    color: var(--av-label);
    font-weight: 500;
    font-family: var(--av-font-label);
    font-size: var(--av-label-size);
    letter-spacing: var(--av-label-spacing);
    text-transform: var(--av-label-transform);
    display: inline-block;
    min-width: 140px;
}

/* The value of a row. Rows added by other modules may not use the span, so the
 * last child stays covered as well. */
.fancy_box ul li .verify-value,
.fancy_box ul li > *:last-child {
    color: var(--av-value);
    font-weight: 600;
    text-align: right;
}

.fancy_box ul li:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.fancy_box a {
    color: var(--av-accent);
    text-decoration: none;
    border-bottom: 1px solid var(--av-accent-soft);
    transition: border-color .15s ease;
}
.fancy_box a:hover {
    border-bottom-color: var(--av-accent);
}

/* Page headings (Registration Verification etc.): the theme colour can be
 * unreadable on a restyled background, so they follow the palette. */
.section h1, .section h2 {
    color: var(--av-heading);
    text-align: center;
    font-family: var(--av-font);
    font-weight: 700;
    letter-spacing: -0.01em;
}

/* Status badge: colour comes from the PHP inline style, only weight here. */
.registration-status,
[data-status] {
    font-weight: 600;
}

.verify-btn {
    display: inline-block;
    padding: 12px 22px;
    font-family: var(--av-font);
    font-size: 14px;
    font-weight: 700;
    border: 0;
    border-radius: var(--av-button-radius);
    cursor: pointer;
    color: var(--av-accent-text);
    background: var(--av-accent);
    text-decoration: none;
    transition: transform .12s ease, box-shadow .12s ease, background .12s ease;
    letter-spacing: 0.02em;
}

.verify-btn:hover {
    background: var(--av-accent-hover);
    transform: translateY(-1px);
    box-shadow: 0 6px 16px var(--av-accent-glow);
}

.verify-btn--register {
    background: var(--av-accent);
    color: var(--av-accent-text);
}

.verify-btn--cancel {
    background: transparent;
    color: var(--av-danger);
    border: 1.5px solid var(--av-danger);
}
.verify-btn--cancel:hover {
    background: var(--av-danger-soft);
    box-shadow: none;
}

.verify-btn--sm {
    padding: 8px 16px;
    font-size: 13px;
}

.verify-actions {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    margin-top: 8px;
}

/* History table (if present) */
.fancy_box table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
    color: var(--av-muted);
}
.fancy_box table th,
.fancy_box table td {
    padding: 8px 6px;
    border-bottom: 1px solid var(--av-border);
    text-align: left;
}
.fancy_box table th {
    color: var(--av-label);
    font-family: var(--av-font-label);
    font-size: var(--av-label-size);
    letter-spacing: var(--av-label-spacing);
    text-transform: var(--av-label-transform);
    font-weight: 500;
}

/* Highlighted section: for the one thing somebody at the door has to read in a
 * second (which station to send the visitor to). Any section can ask for it by
 * setting 'class' => 'fancy_box--highlight'. */
.fancy_box--highlight {
    background: var(--av-highlight-bg);
    border-left-width: 6px;
}

.fancy_box--highlight ul li {
    align-items: center;
    padding: 4px 0;
    border-bottom: none;
}

/* The VALUE is what matters here (which station), so the label stays quiet and
 * the value is the big thing on the row. */
.fancy_box--highlight ul li strong {
    font-size: var(--av-label-size);
    font-weight: 500;
    opacity: .75;
}

/* A single row repeats what the box title already says, so only the value is
 * left standing. */
.fancy_box--highlight ul li:only-child strong {
    display: none;
}

.fancy_box--highlight ul li .verify-value {
    color: var(--av-highlight-text);
    font-size: 40px;
    line-height: 1.1;
    font-weight: 800;
    letter-spacing: -0.02em;
}

@media only screen and (max-width: 767px) {
    .fancy_box--highlight ul li .verify-value {
        font-size: 34px;
    }
}

@media only screen and (max-width: 767px) {
    .section_wrapper.clearfix {
        padding: 0 16px;
    }
    .fancy_box {
        padding: 18px 20px;
    }
    .fancy_box ul li {
        flex-direction: column;
        gap: 4px;
    }
    .fancy_box ul li .verify-value,
    .fancy_box ul li > *:last-child {
        text-align: left;
    }
    .fancy_box ul li strong {
        min-width: 0;
    }
}
</style>
