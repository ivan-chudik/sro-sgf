<?php

namespace Alttag\Registrations\Module;

use Alttag\Registrations\Core;
use Alttag\Registrations\Selection\SessionSelection;
use Alttag\Registrations\Settings;

if (!defined('ABSPATH')) {
    exit;
}

class SessionModule extends AbstractModule
{
    /** @var SessionSelection */
    private $selection;

    public function getId(): string
    {
        return 'sessions';
    }

    public function getName(): string
    {
        return __('Sessions', 'alttag-registrations');
    }

    public function getDescription(): string
    {
        return __('Session date picker with optional time slots, capacity limits, and auto-close before event.', 'alttag-registrations');
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
            'sessions' => [
                'session_field_label' => [
                    'label' => __('Session Field Label', 'alttag-registrations'),
                    'type' => 'text',
                    'default' => __('Training date', 'alttag-registrations'),
                ],
                'session_slot_label' => [
                    'label' => __('Slot Field Label', 'alttag-registrations'),
                    'type' => 'text',
                    'default' => __('Time slot', 'alttag-registrations'),
                ],
                'show_product_column' => [
                    'label' => __('Show product column & filter', 'alttag-registrations'),
                    'type' => 'checkbox',
                    'default' => false,
                    'description' => __('Show product name column and product filter in participant list', 'alttag-registrations'),
                ],
                'enable_session_duplicate_check' => [
                    'label' => __('Block duplicate session registrations', 'alttag-registrations'),
                    'type' => 'checkbox',
                    'default' => true,
                    'description' => __('Reject checkout if the same email is already registered for the same product and session date.', 'alttag-registrations'),
                ],
            ],
        ];
    }

    public function registerHooks(): void
    {
        $this->selection = new SessionSelection();

        // Register selection type
        add_action('alttag_registrations_register_selection_types', function () {
            \Alttag\Registrations\Selection\SelectionManager::register($this->selection);
        });

        // Labels
        add_filter('alttag_registrations_session_field_label', [$this, 'getFieldLabel']);
        add_filter('alttag_registrations_session_slot_label', [$this, 'getSlotLabel']);

        // Slot session/order handling (slot is a single value, not part of SelectionManager data flow)
        add_action('woocommerce_checkout_update_order_review', [$this, 'saveSlotToSession']);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'saveSlotToOrder'], 15);

        // Duplicate registration check (email + product + session)
        add_action('woocommerce_checkout_process', [$this, 'validateDuplicateSessionRegistration']);

        // Pre-select session from URL parameter (for landing pages)
        add_action('wp_loaded', [$this, 'handleSessionFromUrl'], 9);
        add_action('alttag_registrations_add_to_cart', [$this, 'handleSessionAfterAddToCart'], 10, 3);

        // Export: ensure session meta values are scalar
        add_filter('alttag_registrations_export_cell_value', [$this, 'formatExportValue'], 10, 4);

        // Product admin
        add_filter('woocommerce_product_data_tabs', [$this, 'addProductTab']);
        add_action('woocommerce_product_data_panels', [$this, 'renderProductPanel']);
        add_action('woocommerce_process_product_meta', [$this, 'saveProductMeta']);

        // Participant data
        add_filter('alttag_registrations_order_data', [$this, 'addToOrderData']);
        add_action('alttag_registrations_participant_create', [$this, 'saveToParticipant'], 10, 2);
        add_filter('alttag_registrations_meta_fields', [$this, 'registerMetaFields']);

        // Admin list
        add_filter('alttag_registrations_participant_columns', [$this, 'addAdminColumns']);
        add_filter('alttag_registrations_participant_column_content', [$this, 'renderAdminColumn'], 10, 3);
        add_action('alttag_registrations_participant_before_filters', [$this, 'renderAdminFilter']);
        add_filter('alttag_registrations_participant_filters_query', [$this, 'applyAdminFilter']);
        add_filter('alttag_registrations_meta_box_sections', [$this, 'addMetaBoxFields']);

        // Email - override event details with session-specific data
        add_filter('alttag_registrations_email_event_details', [$this, 'overrideEmailEventDetails'], 10, 3);
        add_filter('alttag_registrations_email_title', [$this, 'overrideEmailTitle'], 10, 3);
        add_action('alttag_registrations_email_after_custom_fields', [$this, 'renderEmailSessionDetails'], 10, 2);

        // Thank you page - override event text and show session details
        add_filter('alttag_registrations_checkout_event_details_text', [$this, 'overrideThankYouEventText'], 10, 3);
        add_action('alttag_registrations_checkout_after_download_links', [$this, 'renderThankYouSessionDetails']);

        // Ticket
        add_filter('alttag_registrations_pdf_ticket_data', [$this, 'addTicketData'], 10, 3);
        add_filter('alttag_registrations_ticket_file_name', [$this, 'addTicketDateToFilename']);
        add_filter('alttag_registrations_ticket_available_shortcodes', [$this, 'addTicketShortcodes']);
        add_filter('alttag_registrations_ticket_field_order_options', [$this, 'addTicketFieldOptions']);

        // Verification
        add_filter('alttag_registrations_verification_sections', [$this, 'addVerificationFields']);
        add_filter('alttag_registrations_customer_data', [$this, 'populateVerificationData'], 10, 3);
        add_filter('alttag_registrations_verification_field_value', [$this, 'formatVerificationValue'], 10, 3);
        add_filter('alttag_registrations_verification_field_label', [$this, 'getVerificationLabel'], 10, 3);

        // Export / Import
        add_filter('alttag_registrations_export_field_order', [$this, 'addExportFields']);
        add_filter('alttag_registrations_import_header_variations', [$this, 'addImportVariations']);
    }

    // =========================================================================
    // Labels
    // =========================================================================

    public function getFieldLabel($label)
    {
        return $this->settings->get('sessions.session_field_label', '') ?: $label;
    }

    public function getSlotLabel($label)
    {
        return $this->settings->get('sessions.session_slot_label', '') ?: $label;
    }

    private function showProductColumn(): bool
    {
        return (bool) $this->settings->get('sessions.show_product_column', false);
    }

    // =========================================================================
    // Product admin
    // =========================================================================

    public function addProductTab($tabs)
    {
        $tabs['session_dates'] = [
            'label'    => __('Sessions', 'alttag-registrations'),
            'target'   => 'session_dates_product_data',
            'class'    => [],
            'priority' => 22,
        ];
        return $tabs;
    }

    public function renderProductPanel()
    {
        global $post;
        $sessions = get_post_meta($post->ID, '_session_dates', true);
        if (!is_array($sessions)) {
            $sessions = [];
        }
        ?>
        <div id="session_dates_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <p class="form-field">
                    <label><?php _e('Session Dates', 'alttag-registrations'); ?></label>
                    <span class="description"><?php _e('Configure session dates with optional time slots.', 'alttag-registrations'); ?></span>
                </p>
                <style>
                    .session-row, .slot-row, .meta-row { display: flex; gap: 8px; padding: 4px 20px; align-items: center; }
                    .slot-row { padding-left: 50px; background: #f9f9f9; }
                    .meta-row { padding-left: 50px; background: #f5f0ff; }
                    .session-row input, .slot-row input, .meta-row input { box-sizing: border-box; }
                    .session-slots-wrap, .session-meta-wrap { margin-bottom: 8px; }
                </style>

                <div id="session-dates-container">
                    <div class="session-header" style="display:flex;gap:8px;padding:2px 20px;font-size:12px;color:#666;">
                        <span style="width:150px"><?php _e('Date', 'alttag-registrations'); ?></span>
                        <span style="width:80px"><?php _e('Time', 'alttag-registrations'); ?></span>
                        <span style="width:150px"><?php _e('Label', 'alttag-registrations'); ?></span>
                        <span style="width:170px" title="<?php esc_attr_e('Exact datetime when registration closes', 'alttag-registrations'); ?>"><?php _e('Close at', 'alttag-registrations'); ?></span>
                        <span style="width:80px"><?php _e('Capacity', 'alttag-registrations'); ?></span>
                    </div>

                    <?php foreach ($sessions as $i => $session) :
                        $has_slots = !empty($session['slots']) && is_array($session['slots']);
                        $session_meta = isset($session['meta']) && is_array($session['meta']) ? $session['meta'] : [];
                    ?>
                    <div class="session-block" data-index="<?php echo $i; ?>">
                        <div class="session-row">
                            <input type="date" name="_session_dates[<?php echo $i; ?>][date]" value="<?php echo esc_attr($session['date'] ?? ''); ?>" style="width:150px" />
                            <input type="time" name="_session_dates[<?php echo $i; ?>][time]" value="<?php echo esc_attr($session['time'] ?? ''); ?>" style="width:80px" placeholder="HH:MM" />
                            <input type="text" name="_session_dates[<?php echo $i; ?>][label]" value="<?php echo esc_attr($session['label'] ?? ''); ?>" style="width:150px" placeholder="<?php esc_attr_e('Label (e.g. city)', 'alttag-registrations'); ?>" />
                            <input type="datetime-local" name="_session_dates[<?php echo $i; ?>][close_at]" value="<?php echo esc_attr($session['close_at'] ?? ''); ?>" style="width:170px" />
                            <?php if (!$has_slots) : ?>
                            <input type="number" name="_session_dates[<?php echo $i; ?>][capacity]" value="<?php echo esc_attr($session['capacity'] ?? ''); ?>" style="width:80px" min="0" placeholder="∞" />
                            <?php else : ?>
                            <span style="width:80px;color:#999;font-size:12px"><?php _e('per slot', 'alttag-registrations'); ?></span>
                            <?php endif; ?>
                            <button type="button" class="button toggle-slots" data-index="<?php echo $i; ?>"><?php echo $has_slots ? __('Hide slots', 'alttag-registrations') : __('+ Slots', 'alttag-registrations'); ?></button>
                            <button type="button" class="button toggle-meta" data-index="<?php echo $i; ?>"><?php echo !empty($session_meta) ? __('Hide meta', 'alttag-registrations') : __('+ Meta', 'alttag-registrations'); ?></button>
                            <button type="button" class="button remove-session">&times;</button>
                        </div>
                        <div class="session-slots-wrap" data-index="<?php echo $i; ?>" style="<?php echo $has_slots ? '' : 'display:none'; ?>">
                            <?php if ($has_slots) :
                                foreach ($session['slots'] as $j => $slot) : ?>
                            <div class="slot-row">
                                <input type="time" name="_session_dates[<?php echo $i; ?>][slots][<?php echo $j; ?>][time]" value="<?php echo esc_attr($slot['time'] ?? ''); ?>" style="width:100px" />
                                <input type="text" name="_session_dates[<?php echo $i; ?>][slots][<?php echo $j; ?>][label]" value="<?php echo esc_attr($slot['label'] ?? ''); ?>" style="width:150px" placeholder="<?php esc_attr_e('Label', 'alttag-registrations'); ?>" />
                                <input type="number" name="_session_dates[<?php echo $i; ?>][slots][<?php echo $j; ?>][capacity]" value="<?php echo esc_attr($slot['capacity'] ?? ''); ?>" style="width:80px" min="0" placeholder="∞" />
                                <button type="button" class="button remove-slot">&times;</button>
                            </div>
                            <?php endforeach;
                            endif; ?>
                            <p style="padding-left:50px">
                                <button type="button" class="button add-slot" data-index="<?php echo $i; ?>"><?php _e('+ Slot', 'alttag-registrations'); ?></button>
                            </p>
                        </div>
                        <div class="session-meta-wrap" data-index="<?php echo $i; ?>" style="<?php echo !empty($session_meta) ? '' : 'display:none'; ?>">
                            <?php $mk = 0; foreach ($session_meta as $key => $val) : ?>
                            <div class="meta-row">
                                <input type="text" name="_session_dates[<?php echo $i; ?>][meta][<?php echo $mk; ?>][key]" value="<?php echo esc_attr($key); ?>" style="width:150px" placeholder="<?php esc_attr_e('Key', 'alttag-registrations'); ?>" />
                                <input type="text" name="_session_dates[<?php echo $i; ?>][meta][<?php echo $mk; ?>][value]" value="<?php echo esc_attr($val); ?>" style="width:250px" placeholder="<?php esc_attr_e('Value', 'alttag-registrations'); ?>" />
                                <button type="button" class="button remove-meta">&times;</button>
                            </div>
                            <?php $mk++; endforeach; ?>
                            <p style="padding-left:50px">
                                <button type="button" class="button add-meta" data-index="<?php echo $i; ?>"><?php _e('+ Meta', 'alttag-registrations'); ?></button>
                            </p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <p style="padding:5px 20px">
                    <button type="button" class="button" id="add-session-date"><?php _e('+ Session Date', 'alttag-registrations'); ?></button>
                </p>
            </div>
        </div>

        <script>
        jQuery(function($) {
            var container = $('#session-dates-container');
            var idx = container.find('.session-block').length;

            $('#add-session-date').on('click', function() {
                var html = '<div class="session-block" data-index="'+idx+'">'
                    + '<div class="session-row">'
                    + '<input type="date" name="_session_dates['+idx+'][date]" style="width:150px" />'
                    + '<input type="time" name="_session_dates['+idx+'][time]" style="width:80px" placeholder="HH:MM" />'
                    + '<input type="text" name="_session_dates['+idx+'][label]" style="width:150px" placeholder="Label" />'
                    + '<input type="datetime-local" name="_session_dates['+idx+'][close_at]" style="width:170px" />'
                    + '<input type="number" name="_session_dates['+idx+'][capacity]" style="width:80px" min="0" placeholder="∞" />'
                    + '<button type="button" class="button toggle-slots" data-index="'+idx+'">+ Slots</button>'
                    + '<button type="button" class="button toggle-meta" data-index="'+idx+'">+ Meta</button>'
                    + '<button type="button" class="button remove-session">&times;</button>'
                    + '</div>'
                    + '<div class="session-slots-wrap" data-index="'+idx+'" style="display:none">'
                    + '<p style="padding-left:50px"><button type="button" class="button add-slot" data-index="'+idx+'">+ Slot</button></p>'
                    + '</div>'
                    + '<div class="session-meta-wrap" data-index="'+idx+'" style="display:none">'
                    + '<p style="padding-left:50px"><button type="button" class="button add-meta" data-index="'+idx+'">+ Meta</button></p>'
                    + '</div>'
                    + '</div>';
                container.append(html);
                idx++;
            });

            container.on('click', '.remove-session', function() {
                $(this).closest('.session-block').remove();
            });

            container.on('click', '.toggle-slots', function() {
                var i = $(this).data('index');
                var wrap = container.find('.session-slots-wrap[data-index="'+i+'"]');
                wrap.toggle();
                var visible = wrap.is(':visible');
                $(this).text(visible ? '<?php _e('Hide slots', 'alttag-registrations'); ?>' : '+ Slots');
                var capInput = $(this).closest('.session-row').find('input[name$="[capacity]"]');
                if (visible) capInput.replaceWith('<span style="width:80px;color:#999;font-size:12px"><?php _e('per slot', 'alttag-registrations'); ?></span>');
            });

            container.on('click', '.toggle-meta', function() {
                var i = $(this).data('index');
                var wrap = container.find('.session-meta-wrap[data-index="'+i+'"]');
                wrap.toggle();
                $(this).text(wrap.is(':visible') ? '<?php _e('Hide meta', 'alttag-registrations'); ?>' : '+ Meta');
            });

            var slotIdx = {};
            container.on('click', '.add-slot', function() {
                var i = $(this).data('index');
                if (!slotIdx[i]) slotIdx[i] = container.find('.session-slots-wrap[data-index="'+i+'"] .slot-row').length;
                var j = slotIdx[i]++;
                var html = '<div class="slot-row">'
                    + '<input type="time" name="_session_dates['+i+'][slots]['+j+'][time]" style="width:100px" />'
                    + '<input type="text" name="_session_dates['+i+'][slots]['+j+'][label]" style="width:150px" placeholder="Label" />'
                    + '<input type="number" name="_session_dates['+i+'][slots]['+j+'][capacity]" style="width:80px" min="0" placeholder="∞" />'
                    + '<button type="button" class="button remove-slot">&times;</button>'
                    + '</div>';
                $(this).closest('p').before(html);
            });

            container.on('click', '.remove-slot', function() {
                $(this).closest('.slot-row').remove();
            });

            var metaIdx = {};
            container.on('click', '.add-meta', function() {
                var i = $(this).data('index');
                if (!metaIdx[i]) metaIdx[i] = container.find('.session-meta-wrap[data-index="'+i+'"] .meta-row').length;
                var m = metaIdx[i]++;
                var html = '<div class="meta-row">'
                    + '<input type="text" name="_session_dates['+i+'][meta]['+m+'][key]" style="width:150px" placeholder="Key" />'
                    + '<input type="text" name="_session_dates['+i+'][meta]['+m+'][value]" style="width:250px" placeholder="Value" />'
                    + '<button type="button" class="button remove-meta">&times;</button>'
                    + '</div>';
                $(this).closest('p').before(html);
            });

            container.on('click', '.remove-meta', function() {
                $(this).closest('.meta-row').remove();
            });
        });
        </script>
        <?php
    }

    public function saveProductMeta($post_id)
    {
        if (!isset($_POST['_session_dates'])) {
            delete_post_meta($post_id, '_session_dates');
            return;
        }

        $raw = $_POST['_session_dates'];
        $clean = [];

        foreach ($raw as $session) {
            $date = sanitize_text_field($session['date'] ?? '');
            if (empty($date)) {
                continue;
            }

            $entry = [
                'date' => $date,
            ];

            // Time (e.g. 17:00)
            $time = sanitize_text_field($session['time'] ?? '');
            if (!empty($time)) {
                $entry['time'] = $time;
            }

            // Close at — exact datetime when registration closes
            $close_at = sanitize_text_field($session['close_at'] ?? '');
            if (!empty($close_at)) {
                // Normalize datetime-local format to "Y-m-d H:i"
                $ts = strtotime(str_replace('T', ' ', $close_at));
                if ($ts) {
                    $entry['close_at'] = date('Y-m-d H:i', $ts);
                }
            }

            // Label (e.g. city name)
            $label = sanitize_text_field($session['label'] ?? '');
            if (!empty($label)) {
                $entry['label'] = $label;
            }

            // Slots
            if (!empty($session['slots']) && is_array($session['slots'])) {
                $slots = [];
                foreach ($session['slots'] as $slot) {
                    $time = sanitize_text_field($slot['time'] ?? '');
                    if (empty($time)) {
                        continue;
                    }
                    $slots[] = [
                        'time' => $time,
                        'label' => sanitize_text_field($slot['label'] ?? $time),
                        'capacity' => absint($slot['capacity'] ?? 0),
                    ];
                }
                if (!empty($slots)) {
                    $entry['slots'] = $slots;
                } else {
                    $cap = $session['capacity'] ?? '';
                    $entry['capacity'] = ($cap !== '' && $cap !== null) ? absint($cap) : 0;
                }
            } else {
                $cap = $session['capacity'] ?? '';
                $entry['capacity'] = ($cap !== '' && $cap !== null) ? absint($cap) : 0;
            }

            // Custom meta key-value pairs
            if (!empty($session['meta']) && is_array($session['meta'])) {
                $meta = [];
                foreach ($session['meta'] as $m) {
                    $key = sanitize_key($m['key'] ?? '');
                    $val = sanitize_text_field($m['value'] ?? '');
                    if (!empty($key)) {
                        $meta[$key] = $val;
                    }
                }
                if (!empty($meta)) {
                    $entry['meta'] = $meta;
                }
            }

            $clean[] = $entry;
        }

        update_post_meta($post_id, '_session_dates', $clean);
    }

    // =========================================================================
    // Participant data
    // =========================================================================

    /**
     * Handle ?session=YYYY-MM-DD URL parameter to pre-select a session.
     * Used for landing pages that link directly to a specific session.
     *
     * Example: /?add-to-cart=5267&session=2026-05-15
     *
     * Runs at priority 9 (before WooCommerceManager's add-to-cart handler at 10).
     * For add-to-cart URLs, setting happens via alttag_registrations_add_to_cart action
     * (since WC cart is empty at wp_loaded priority 9).
     */
    public function handleSessionFromUrl()
    {
        if (empty($_GET['session'])) {
            return;
        }

        // For add-to-cart URLs, we let the action handler do it (after cart is populated)
        if (!empty($_GET['add-to-cart'])) {
            return;
        }

        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        // Resolve product from cart
        $product_id = 0;
        foreach (WC()->cart ? WC()->cart->get_cart() : [] as $item) {
            $product_id = $item['product_id'];
            break;
        }

        if (!$product_id) {
            return;
        }

        $this->setLockedSession($product_id, sanitize_text_field($_GET['session']));
    }

    /**
     * Set pre-selected session from URL after product was added to cart.
     * Triggered by alttag_registrations_add_to_cart action.
     */
    public function handleSessionAfterAddToCart($product_id, $cart, $session)
    {
        if (empty($_GET['session'])) {
            return;
        }

        $this->setLockedSession($product_id, sanitize_text_field($_GET['session']));
    }

    /**
     * Validate and store pre-selected session with lock flag.
     */
    private function setLockedSession($product_id, $requested_date)
    {
        if (!$product_id || empty($requested_date)) {
            return;
        }

        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        // Validate requested date exists in product session config
        $sessions = $this->selection ? $this->selection->getSessionConfig($product_id) : [];
        $valid = false;
        foreach ($sessions as $s) {
            if (($s['date'] ?? '') === $requested_date) {
                $valid = true;
                break;
            }
        }

        if (!$valid) {
            return;
        }

        // Store in session as array (SelectionManager format) and set lock flag with product_id
        WC()->session->set('selected_session', [$requested_date]);
        WC()->session->set('selected_session_locked', (string) $product_id);
    }

    public function saveSlotToSession($post_data)
    {
        parse_str($post_data, $data);
        $session = WC()->session;
        if ($session && isset($data['selected_session_slot'])) {
            $session->set('selected_session_slot', sanitize_text_field($data['selected_session_slot']));
        }
    }

    public function saveSlotToOrder($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Save session date from SelectionManager data (stored as array)
        $session_dates = WC()->session ? WC()->session->get('selected_session') : null;
        if (is_array($session_dates) && !empty($session_dates)) {
            $order->update_meta_data('selected_session', $session_dates[0]);
        }

        // Save slot
        $slot = WC()->session ? WC()->session->get('selected_session_slot') : '';
        if (!empty($slot)) {
            $order->update_meta_data('selected_session_slot', $slot);
        }

        $order->save();

        // Clear lock flag after order is placed
        if (WC()->session) {
            WC()->session->__unset('selected_session_locked');
        }
    }

    public function addToOrderData($order_data)
    {
        $order_id = $order_data['order_id'] ?? null;
        if (!$order_id) {
            return $order_data;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return $order_data;
        }

        $session = $order->get_meta('selected_session');
        if ($session) {
            $order_data['selected_session'] = $session;
        }
        $slot = $order->get_meta('selected_session_slot');
        if ($slot) {
            $order_data['selected_session_slot'] = $slot;
        }

        return $order_data;
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

        $session_date = $order->get_meta('selected_session');
        if ($session_date) {
            update_post_meta($participant_id, 'selected_session', $session_date);
        }

        $slot = $order->get_meta('selected_session_slot');
        if ($slot) {
            update_post_meta($participant_id, 'selected_session_slot', $slot);
        }

        // Save product_id and product_name
        $product_id = 0;
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if ($product_id) {
                update_post_meta($participant_id, 'product_id', $product_id);
                update_post_meta($participant_id, 'product_name', $item->get_name());
                break;
            }
        }

        // Save session meta (label + custom key-value pairs) from product config
        if ($session_date && $product_id) {
            $session_config = $this->selection
                ? $this->selection->getSessionConfig($product_id)
                : [];

            foreach ($session_config as $cfg) {
                if (($cfg['date'] ?? '') !== $session_date) {
                    continue;
                }

                if (!empty($cfg['label'])) {
                    update_post_meta($participant_id, 'session_label', $cfg['label']);
                }

                if (!empty($cfg['time'])) {
                    update_post_meta($participant_id, 'session_time', $cfg['time']);
                }

                if (!empty($cfg['meta']) && is_array($cfg['meta'])) {
                    update_post_meta($participant_id, 'session_meta', $cfg['meta']);
                    foreach ($cfg['meta'] as $key => $val) {
                        update_post_meta($participant_id, 'session_' . $key, $val);
                    }
                }
                break;
            }
        }

        // Always save company_name
        $company = $order->get_billing_company();
        if (!empty($company)) {
            update_post_meta($participant_id, 'company_name', $company);
        }
    }

    public function registerMetaFields($fields)
    {
        $fields['selected_session'] = [
            'label' => __('Session Date', 'alttag-registrations'),
            'type' => 'text',
            'readonly' => true,
        ];
        $fields['selected_session_slot'] = [
            'label' => __('Time Slot', 'alttag-registrations'),
            'type' => 'text',
            'readonly' => true,
        ];
        $fields['product_id'] = [
            'label' => __('Product ID', 'alttag-registrations'),
            'type' => 'text',
            'readonly' => true,
        ];
        $fields['product_name'] = [
            'label' => __('Product Name', 'alttag-registrations'),
            'type' => 'text',
            'readonly' => true,
        ];
        return $fields;
    }

    // =========================================================================
    // Admin columns & filters
    // =========================================================================

    public function addAdminColumns($columns)
    {
        $new = [];
        foreach ($columns as $key => $label) {
            if ($key === 'actions') {
                if ($this->showProductColumn()) {
                    $new['product_name'] = __('Product', 'alttag-registrations');
                }
                $new['selected_session'] = __('Session', 'alttag-registrations');
            }
            $new[$key] = $label;
        }
        return $new;
    }

    public function renderAdminColumn($content, $column, $post_id)
    {
        if ($column === 'product_name' && $this->showProductColumn()) {
            $name = get_post_meta($post_id, 'product_name', true);
            return $name ? esc_html($name) : '—';
        }

        if ($column === 'selected_session') {
            $date = get_post_meta($post_id, 'selected_session', true);
            if (is_array($date)) {
                $date = reset($date);
            }
            if (!$date) {
                return '—';
            }
            $display = esc_html(\Alttag\Registrations\format_date($date));
            $raw_slot = get_post_meta($post_id, 'selected_session_slot', true);
            $slot = is_string($raw_slot) ? $raw_slot : '';
            if ($slot) {
                $display .= '<br><small>' . esc_html($slot) . '</small>';
            }
            return $display;
        }

        return $content;
    }

    public function renderAdminFilter()
    {
        global $wpdb;

        // Session date + optional time slot filter. URL value format:
        // "YYYY-MM-DD" (date only, legacy) or "YYYY-MM-DD|slot" (specific slot).
        // Each distinct (selected_session, selected_session_slot) pair is one
        // option; sessions without a slot stay as date-only entries.
        $current = isset($_GET['filter_session']) ? sanitize_text_field($_GET['filter_session']) : '';
        $sessions = $wpdb->get_results(
            "SELECT DISTINCT date_pm.meta_value AS session_date,
                    COALESCE(slot_pm.meta_value, '') AS session_slot
             FROM {$wpdb->postmeta} date_pm
             JOIN {$wpdb->posts} p ON date_pm.post_id = p.ID
                 AND p.post_type = 'participant' AND p.post_status = 'publish'
             LEFT JOIN {$wpdb->postmeta} slot_pm ON slot_pm.post_id = date_pm.post_id
                 AND slot_pm.meta_key = 'selected_session_slot'
             WHERE date_pm.meta_key = 'selected_session' AND date_pm.meta_value != ''
             ORDER BY date_pm.meta_value ASC, slot_pm.meta_value ASC"
        );

        if (!empty($sessions)) {
            echo '<select name="filter_session">';
            echo '<option value="">' . esc_html__('All sessions', 'alttag-registrations') . '</option>';
            foreach ($sessions as $session) {
                $value = $session->session_slot !== ''
                    ? $session->session_date . '|' . $session->session_slot
                    : $session->session_date;
                $label = \Alttag\Registrations\format_date($session->session_date);
                // selected_session_slot doubles as the selection-data meta, so it can
                // hold a serialised map like a:1:{s:10:"2026-09-11";i:1} instead of a
                // time window. Never print that at the user.
                $slot = $session->session_slot;
                if (is_string($slot) && $slot !== '' && !is_serialized($slot)) {
                    $label .= ' (' . $slot . ')';
                }
                echo '<option value="' . esc_attr($value) . '"' . selected($current, $value, false) . '>'
                    . esc_html($label) . '</option>';
            }
            echo '</select>';
        }
    }

    public function applyAdminFilter($meta_query)
    {
        if (isset($_GET['filter_session']) && $_GET['filter_session'] !== '') {
            $raw = sanitize_text_field($_GET['filter_session']);
            [$date, $slot] = array_pad(explode('|', $raw, 2), 2, '');
            if ($date !== '') {
                $meta_query[] = ['key' => 'selected_session', 'value' => $date];
            }
            if ($slot !== '') {
                $meta_query[] = ['key' => 'selected_session_slot', 'value' => $slot];
            }
        }
        return $meta_query;
    }

    public function addMetaBoxFields($sections)
    {
        if (!isset($sections['registration']['fields'])) {
            $sections['registration']['fields'] = [];
        }
        $sections['registration']['fields'][] = 'selected_session';
        $sections['registration']['fields'][] = 'selected_session_slot';
        return $sections;
    }

    // =========================================================================
    // Email overrides - use session-specific date/location instead of product-level
    // =========================================================================

    private function getParticipantSessionData($order, $participant_id)
    {
        $session_date = '';
        $session_time = '';
        $session_label = '';
        $session_address = '';
        $session_locative = '';
        $session_slot = '';
        $session_slot_label = '';
        $product_id_for_slot = 0;

        if ($participant_id) {
            $session_date = get_post_meta($participant_id, 'selected_session', true);
            if (is_array($session_date)) {
                $session_date = reset($session_date);
            }
            $session_time = get_post_meta($participant_id, 'session_time', true) ?: '';
            $session_label = get_post_meta($participant_id, 'session_label', true);
            $session_address = get_post_meta($participant_id, 'session_address', true) ?: '';
            $session_locative = get_post_meta($participant_id, 'session_location_locative', true);
            $raw_slot = get_post_meta($participant_id, 'selected_session_slot', true);
            $session_slot = is_string($raw_slot) ? $raw_slot : '';
            $product_id_for_slot = (int) get_post_meta($participant_id, 'product_id', true);
        } elseif ($order instanceof \WC_Order) {
            $session_date = $order->get_meta('selected_session');
            if (is_array($session_date)) {
                $session_date = reset($session_date);
            }
            $raw_slot = $order->get_meta('selected_session_slot');
            $session_slot = is_string($raw_slot) ? $raw_slot : '';
            if ($session_date) {
                foreach ($order->get_items() as $item) {
                    $pid = $item->get_product_id();
                    if ($pid && $this->selection) {
                        foreach ($this->selection->getSessionConfig($pid) as $cfg) {
                            if (($cfg['date'] ?? '') === $session_date) {
                                $session_time = $cfg['time'] ?? '';
                                $session_label = $cfg['label'] ?? '';
                                $session_address = $cfg['meta']['address'] ?? '';
                                $session_locative = $cfg['meta']['location_locative'] ?? '';
                                $product_id_for_slot = (int) $pid;
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        if (empty($session_date)) {
            return null;
        }

        // Resolve the slot's full label (e.g. "Doobedie - 2. VLNA (08:50 - 11:20 hod.)")
        // so emails / tickets / thank-you boxes can show it instead of the raw start time.
        if ($session_slot !== '' && $product_id_for_slot > 0 && $this->selection) {
            foreach ($this->selection->getSessionConfig($product_id_for_slot) as $cfg) {
                if (($cfg['date'] ?? '') !== $session_date) {
                    continue;
                }
                foreach ((array) ($cfg['slots'] ?? []) as $slot_cfg) {
                    if (($slot_cfg['time'] ?? '') === $session_slot) {
                        $session_slot_label = (string) ($slot_cfg['label'] ?? '');
                        break 2;
                    }
                }
            }
        }

        // A session without its own locative fell straight back to the session
        // LABEL, which is a nominative venue name ("Fakulta matematiky, fyziky
        // a informatiky UK, Mlynská dolina, …") and every sentence that uses
        // this value hardcodes the preposition ("… ktoré sa uskutoční %2$s v
        // %3$s"), so it rendered as "v Fakulta matematiky …". The product's
        // venue_locative is already declined, so it goes before the label.
        if (!$session_locative) {
            $session_locative = Settings::getValue(
                'general.venue_locative',
                $product_id_for_slot ?: null
            );
        }

        return [
            'date' => $session_date,
            'formatted_date' => \Alttag\Registrations\format_date($session_date),
            'time' => $session_time,
            'label' => $session_label,
            'address' => $session_address,
            'locative' => $session_locative ?: $session_label,
            'slot' => $session_slot,
            'slot_label' => $session_slot_label,
        ];
    }

    public function overrideThankYouEventText($text, $order, $ctx)
    {
        $data = $this->getParticipantSessionData($order, null);
        if (!$data) {
            return $text;
        }

        $event_name = \Alttag\Registrations\get_event_name();
        $location = $data['locative'];

        if (!empty($location)) {
            return sprintf(
                __('we look forward to seeing you at <strong>%1$s</strong>, which will take place on <strong>%2$s</strong> at <strong>%3$s</strong>.', 'alttag-registrations'),
                $event_name,
                $data['formatted_date'],
                $location
            );
        }

        return sprintf(
            __('we look forward to seeing you at <strong>%1$s</strong>, which will take place on <strong>%2$s</strong>.', 'alttag-registrations'),
            $event_name,
            $data['formatted_date']
        );
    }

    public function renderThankYouSessionDetails($order)
    {
        $data = $this->getParticipantSessionData($order, null);
        if (!$data) {
            return;
        }
        ?>
        <div class="alttag-session-info-box" style="margin: 15px 0; padding: 15px 20px; background: #f0f4f8; border-left: 4px solid currentColor; border-radius: 4px; font-size: 14px; line-height: 1.8;">
            <strong><?php echo esc_html($this->getFieldLabel(__('Session', 'alttag-registrations'))); ?>:</strong>
            <?php echo esc_html($data['formatted_date']); ?>
            <?php // "Čas" is redundant when a slot is present — the slot already carries the start time. ?>
            <?php if (!empty($data['time']) && empty($data['slot'])) : ?>
                <br><strong><?php echo esc_html__('Time', 'alttag-registrations'); ?>:</strong>
                <?php echo esc_html($data['time']); ?>
            <?php endif; ?>
            <?php if (!empty($data['slot'])) : ?>
                <br><strong><?php echo esc_html($this->getSlotLabel(__('Time slot', 'alttag-registrations'))); ?>:</strong>
                <?php echo esc_html(!empty($data['slot_label']) ? $data['slot_label'] : $data['slot']); ?>
            <?php endif; ?>
            <?php if (!empty($data['label'])) :
                $loc = $data['label'];
                if (!empty($data['address'])) $loc .= ', ' . $data['address'];
            ?>
                <br><strong><?php echo esc_html(__('Location', 'alttag-registrations')); ?>:</strong>
                <?php echo esc_html($loc); ?>
            <?php endif; ?>
            <?php do_action('alttag_registrations_thankyou_session_info_box', $order, $data); ?>
        </div>
        <?php
        // Signal that the session box rendered so other modules can skip
        // their standalone thank-you blocks (they hooked inside the box).
        $GLOBALS['_alttag_session_box_rendered'] = true;
    }

    public function renderEmailSessionDetails($order, $participant_id = null)
    {
        $data = $this->getParticipantSessionData($order, $participant_id);
        if (!$data) {
            return;
        }

        $primary_color = apply_filters('alttag_registrations_email_primary_color', '#323232');
        $style = 'width:100%;box-sizing:border-box;margin:0 0 10px;color:' . $primary_color
            . ';font-size:14px;font-family:Arial,Helvetica,sans-serif;line-height:1.6;';

        $session_label = $this->getFieldLabel(__('Session', 'alttag-registrations'));
        ?>
        <div style="<?php echo $style; ?>">
            <?php echo esc_html($session_label . ': ' . $data['formatted_date']); ?>
        </div>
        <?php // "Čas" is redundant when a slot is present — the slot already carries the start time. ?>
        <?php if (!empty($data['time']) && empty($data['slot'])) : ?>
        <div style="<?php echo $style; ?>">
            <?php echo esc_html(__('Time', 'alttag-registrations') . ': ' . $data['time']); ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($data['slot'])) : ?>
        <div style="<?php echo $style; ?>">
            <?php $slot_display = !empty($data['slot_label']) ? $data['slot_label'] : $data['slot']; ?>
            <?php echo esc_html($this->getSlotLabel(__('Time slot', 'alttag-registrations')) . ': ' . $slot_display); ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($data['label'])) :
            $location_display = $data['label'];
            if (!empty($data['address'])) {
                $location_display .= ', ' . $data['address'];
            }
        ?>
        <div style="<?php echo $style; ?>">
            <?php echo esc_html(__('Location', 'alttag-registrations') . ': ' . $location_display); ?>
        </div>
        <?php endif;
    }

    public function overrideEmailEventDetails($content, $order, $participant_id = null)
    {
        $data = $this->getParticipantSessionData($order, $participant_id);
        if (!$data) {
            return $content;
        }

        $hadCtx = false;
        if ($participant_id) {
            \Alttag\Registrations\RegistrationContext::forParticipant($participant_id);
            $hadCtx = true;
        } elseif ($order instanceof \WC_Order) {
            \Alttag\Registrations\RegistrationContext::forOrder($order);
            $hadCtx = true;
        }

        try {
            $event_name = \Alttag\Registrations\get_event_name();
            $location = $data['locative'] ?: \Alttag\Registrations\get_event_location('locative');
        } finally {
            if ($hadCtx) {
                \Alttag\Registrations\RegistrationContext::reset();
            }
        }

        if (!empty($location)) {
            return sprintf(
                __('%1$s, which will take place on %2$s at %3$s', 'alttag-registrations'),
                $event_name,
                $data['formatted_date'],
                $location
            );
        }

        return sprintf(
            __('%1$s, which will take place on %2$s', 'alttag-registrations'),
            $event_name,
            $data['formatted_date']
        );
    }

    public function overrideEmailTitle($title, $order, $participant_id = null)
    {
        $data = $this->getParticipantSessionData($order, $participant_id);
        if (!$data) {
            return $title;
        }

        // Establish product context so get_event_name() resolves against
        // this participant/order's product meta rather than the (likely empty)
        // cart context active during background email sends.
        $hadCtx = false;
        if ($participant_id) {
            \Alttag\Registrations\RegistrationContext::forParticipant($participant_id);
            $hadCtx = true;
        } elseif ($order instanceof \WC_Order) {
            \Alttag\Registrations\RegistrationContext::forOrder($order);
            $hadCtx = true;
        }

        try {
            $event_name = \Alttag\Registrations\get_event_name();
        } finally {
            if ($hadCtx) {
                \Alttag\Registrations\RegistrationContext::reset();
            }
        }

        $date_str = $data['formatted_date'];
        if (!empty($data['label'])) {
            $date_str .= ', ' . $data['label'];
        }

        return $event_name . '<br>' . $date_str;
    }

    // =========================================================================
    // Ticket
    // =========================================================================

    public function addTicketShortcodes($shortcodes)
    {
        $session_codes = [
            '{session_date}',
            '{session_time}',
            '{session_slot}',
            '{session_location}',
        ];

        // Add dynamic {session_KEY} from product meta if editing a specific product
        $product_id = isset($_GET['ticket_product_id']) ? (int) $_GET['ticket_product_id'] : 0;
        if ($product_id && $this->selection) {
            $config = $this->selection->getSessionConfig($product_id);
            foreach ($config as $session) {
                if (!empty($session['meta']) && is_array($session['meta'])) {
                    foreach (array_keys($session['meta']) as $key) {
                        $code = '{session_' . $key . '}';
                        if (!in_array($code, $session_codes, true)) {
                            $session_codes[] = $code;
                        }
                    }
                }
            }
        }

        return array_merge($shortcodes, $session_codes);
    }

    /**
     * Expose session virtual fields on the Ticket Designer reorder UI.
     * Only called when this module is active (hook is only registered in registerHooks()).
     */
    public function addTicketFieldOptions($options)
    {
        $session_fields = [
            'session_product' => __('Product', 'alttag-registrations'),
            'session_date' => __('Session', 'alttag-registrations'),
            'session_location' => __('Location', 'alttag-registrations'),
        ];

        // Prepend so session fields appear at the top (preserves previous order)
        return $session_fields + $options;
    }

    public function addTicketData($pdf_data, $data, $variable_symbol)
    {
        $pid = $data['id'] ?? null;
        if (!$pid) {
            return $pdf_data;
        }

        // Build prepend items in display order: Product, Session, Location
        $prepend = [];

        $product_name = get_post_meta($pid, 'product_name', true);
        if ($product_name) {
            $prepend[] = [
                'key' => 'session_product',
                'label' => __('Product', 'alttag-registrations'),
                'value' => $product_name,
            ];
        }

        $date = get_post_meta($pid, 'selected_session', true);
        if (is_array($date)) {
            $date = reset($date);
        }
        if ($date) {
            $display = \Alttag\Registrations\format_date($date);
            $raw_slot = get_post_meta($pid, 'selected_session_slot', true);
            $slot = is_string($raw_slot) ? $raw_slot : '';
            if ($slot) {
                $slot_display = $slot;
                $product_id = (int) get_post_meta($pid, 'product_id', true);
                if ($product_id > 0 && $this->selection) {
                    foreach ($this->selection->getSessionConfig($product_id) as $cfg) {
                        if (($cfg['date'] ?? '') !== $date) {
                            continue;
                        }
                        foreach ((array) ($cfg['slots'] ?? []) as $slot_cfg) {
                            if (($slot_cfg['time'] ?? '') === $slot && !empty($slot_cfg['label'])) {
                                $slot_display = (string) $slot_cfg['label'];
                                break 2;
                            }
                        }
                    }
                }
                $display .= ', ' . $slot_display;
            }
            $prepend[] = [
                'key' => 'session_date',
                'label' => $this->getFieldLabel(__('Session', 'alttag-registrations')),
                'value' => $display,
            ];
        }

        $session_label = get_post_meta($pid, 'session_label', true);
        if ($session_label) {
            $prepend[] = [
                'key' => 'session_location',
                'label' => __('Location', 'alttag-registrations'),
                'value' => $session_label,
            ];
        }

        // Product, Session, Location — then original participant fields
        $pdf_data['first_column'] = array_merge($prepend, $pdf_data['first_column']);

        return $pdf_data;
    }

    public function addTicketDateToFilename($filename)
    {
        // This runs in ticket generation context where participant is set
        $ctx = \Alttag\Registrations\ctx();
        $participant = $ctx->participant();
        if ($participant) {
            $date = $participant->getMeta('selected_session');
            if ($date) {
                $filename .= '-' . date('Y-m-d', strtotime($date));
            }
        }
        return $filename;
    }

    // =========================================================================
    // Verification
    // =========================================================================

    public function addVerificationFields($sections)
    {
        if (isset($sections['registration']['fields'])) {
            foreach (['selected_session', 'selected_session_slot'] as $f) {
                if (!in_array($f, $sections['registration']['fields'], true)) {
                    $sections['registration']['fields'][] = $f;
                }
            }
        }
        return $sections;
    }

    public function populateVerificationData($data, $participant_id, $participant)
    {
        $session = get_post_meta($participant_id, 'selected_session', true);
        $data['selected_session'] = is_array($session) ? reset($session) : (string) $session;

        $slot = get_post_meta($participant_id, 'selected_session_slot', true);
        $data['selected_session_slot'] = is_string($slot) ? $slot : '';

        return $data;
    }

    public function formatVerificationValue($value, $field_id, $data)
    {
        if ($field_id === 'selected_session' && !empty($value)) {
            return \Alttag\Registrations\format_date($value);
        }
        return $value;
    }

    public function getVerificationLabel($label, $field_id, $data)
    {
        $map = [
            'selected_session' => $this->getFieldLabel(__('Session', 'alttag-registrations')),
            'selected_session_slot' => $this->getSlotLabel(__('Time slot', 'alttag-registrations')),
        ];
        return $map[$field_id] ?? $label;
    }

    // =========================================================================
    // Export / Import
    // =========================================================================

    public function formatExportValue($value, $key, $participant, $participant_id)
    {
        if ($key === 'selected_session') {
            if (is_array($value)) {
                $value = reset($value);
            }
            return is_string($value) ? $value : '';
        }
        if ($key === 'selected_session_slot') {
            return is_string($value) ? $value : '';
        }
        if ($key === 'session_meta' && is_array($value)) {
            return wp_json_encode($value);
        }
        return $value;
    }

    public function addExportFields($fields)
    {
        $fields[] = 'selected_session';
        $fields[] = 'selected_session_slot';
        $fields[] = 'product_name';
        return $fields;
    }

    public function addImportVariations($variations)
    {
        $variations['session date'] = 'selected_session';
        $variations['session'] = 'selected_session';
        $variations['termín'] = 'selected_session';
        $variations['termin'] = 'selected_session';
        $variations['termín školenia'] = 'selected_session';
        $variations['training date'] = 'selected_session';
        $variations['time slot'] = 'selected_session_slot';
        $variations['slot'] = 'selected_session_slot';
        $variations['časový slot'] = 'selected_session_slot';
        $variations['product name'] = 'product_name';
        $variations['produkt'] = 'product_name';
        $variations['názov produktu'] = 'product_name';
        return $variations;
    }

    // =========================================================================
    // Duplicate session registration check
    // =========================================================================

    /**
     * Block checkout if the same email is already registered for the same
     * product on the same session date.
     *
     * Uses the registration context (cart product IDs, selection-stored session)
     * instead of touching $_POST or WC()->session directly.
     */
    public function validateDuplicateSessionRegistration()
    {
        if (!$this->settings->get('sessions.enable_session_duplicate_check', true)) {
            return;
        }

        // Site-level escape hatch, mirrors the buyer-side duplicate check.
        if (apply_filters('alttag_registrations_skip_session_duplicate_check', false)) {
            return;
        }

        $email = isset($_POST['billing_email']) ? sanitize_email(wp_unslash($_POST['billing_email'])) : '';
        if (empty($email)) {
            return;
        }

        $ctx = \Alttag\Registrations\ctx();
        $cart = $ctx->cart();
        if (!$cart || empty($cart->productIds)) {
            return;
        }

        // Selection holds session date — single source of truth.
        $selected_session = $this->selection->getFromSession();
        if (is_array($selected_session)) {
            $selected_session = reset($selected_session);
        }
        if (empty($selected_session) || !is_string($selected_session)) {
            return;
        }

        foreach ($cart->productIds as $product_id) {
            $product_id = (int) $product_id;

            // Module must be active for this product in this context.
            if (!$this->isEnabledForProduct($product_id)) {
                continue;
            }

            // Per-product skip flag (independent of email-duplicate skip).
            if (get_post_meta($product_id, '_alttag_skip_session_duplicate_check', true) === '1') {
                continue;
            }

            $existing = get_posts([
                'post_type' => 'participant',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    'relation' => 'AND',
                    ['key' => 'email', 'value' => $email, 'compare' => '='],
                    ['key' => 'product_id', 'value' => (string) $product_id, 'compare' => '='],
                    ['key' => 'selected_session', 'value' => $selected_session, 'compare' => '='],
                ],
            ]);

            if (empty($existing)) {
                continue;
            }

            $has_active = false;
            foreach ($existing as $pid) {
                $pState = \Alttag\Registrations\ParticipantState::get($pid);
                if (!$pState) {
                    $has_active = true;
                    break;
                }
                $pOrder = $pState->order();
                if (!$pOrder || in_array($pOrder->get_status(), ['completed', 'processing'], true)) {
                    $has_active = true;
                    break;
                }
            }
            if (!$has_active) {
                continue;
            }

            $product = wc_get_product($product_id);
            $product_name = $product ? $product->get_name() : '#' . $product_id;
            $formatted_date = \Alttag\Registrations\format_date($selected_session);

            wc_add_notice(
                sprintf(
                    __('The email %1$s is already registered for "%2$s" on %3$s.', 'alttag-registrations'),
                    '<strong>' . esc_html($email) . '</strong>',
                    '<strong>' . esc_html($product_name) . '</strong>',
                    '<strong>' . esc_html($formatted_date) . '</strong>'
                ),
                'error'
            );
        }
    }
}
