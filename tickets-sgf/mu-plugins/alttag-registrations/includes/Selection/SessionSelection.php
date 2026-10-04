<?php

namespace Alttag\Registrations\Selection;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single-select session picker with optional time slots.
 *
 * Supports two modes:
 * - Date only: dropdown with dates, each with capacity and close-before-days
 * - Date + time slots: date dropdown → slot dropdown, per-date slots with capacity
 *
 * Product meta '_session_dates':
 *   Date only:   [['date' => '2026-03-24', 'capacity' => 16, 'close_at' => '2026-03-09 00:00'], ...]
 *   With slots:  [['date' => '2026-03-24', 'close_at' => '2026-03-09 00:00', 'slots' => [
 *                    ['time' => '08:00', 'label' => '08:00 - 12:00', 'capacity' => 8], ...
 *                 ]], ...]
 */
class SessionSelection extends AbstractSelection
{
    public function __construct()
    {
        $this->id = 'sessions';
        $this->label = 'Session'; // Translated lazily in getLabel()
        $this->meta_key = 'selected_session';
        $this->data_meta_key = 'selected_session_slot';
        $this->product_meta_key = '_session_dates';
    }

    public function getLabel()
    {
        return apply_filters('alttag_registrations_session_field_label', __('Session', 'alttag-registrations'));
    }

    public function getAssets(): array
    {
        return [
            'css' => ['css/selection-sessions.css'],
            'js' => ['js/selection-sessions.js'],
        ];
    }

    // =========================================================================
    // Product configuration
    // =========================================================================

    public function isActiveForProduct($product_id)
    {
        $dates = $this->getSessionConfig($product_id);
        return !empty($dates);
    }

    public function getAvailableOptions($product_id)
    {
        $dates = $this->getSessionConfig($product_id);
        $options = [];
        foreach ($dates as $session) {
            $date = $session['date'] ?? '';
            if ($date) {
                $options[$date] = \Alttag\Registrations\format_date($date, 'compact');
            }
        }
        return $options;
    }

    public function getMaxPerOption($product_id)
    {
        return 0; // Single selection
    }

    /**
     * Get raw session config from product meta.
     */
    public function getSessionConfig($product_id)
    {
        $dates = get_post_meta($product_id, '_session_dates', true);
        return is_array($dates) ? $dates : [];
    }

    /**
     * Check if a product has time slots enabled.
     */
    public function hasTimeSlots($product_id)
    {
        $dates = $this->getSessionConfig($product_id);
        foreach ($dates as $session) {
            if (!empty($session['slots']) && is_array($session['slots'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get bookings count for a date (and optionally a slot).
     */
    public function getBookingsCount($date, $slot = null, $product_id = 0)
    {
        // Group seating's admin overview already treats the participant ledger
        // as truth (including released-order filtering and multi-person posts).
        // Use that same ledger for the customer-facing remaining-seat count.
        if ($slot !== null
            && class_exists('Alttag\\Registrations\\GroupSeating')
            && \Alttag\Registrations\GroupSeating::isEnabled($product_id)) {
            return array_sum(
                \Alttag\Registrations\GroupSeating::participantLedger($product_id, (string) $slot)
            );
        }

        $meta_query = [
            ['key' => 'selected_session', 'value' => $date],
        ];

        if ($slot !== null) {
            $meta_query[] = ['key' => 'selected_session_slot', 'value' => $slot];
        }

        if ($product_id) {
            $meta_query[] = ['key' => 'product_id', 'value' => $product_id];
        }

        $ids = get_posts([
            'post_type'   => 'participant',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => $meta_query,
        ]);

        // Capacity is measured in persons. A participant post can represent
        // multiple persons via ParticipantTypes (e.g. 2 parents + 3 children = 5).
        $total = 0;
        foreach ($ids as $pid) {
            $data = get_post_meta($pid, 'selected_participant_types_data', true);
            if (is_array($data) && !empty($data)) {
                $total += array_sum(array_map('intval', $data));
                continue;
            }
            $total += 1;
        }
        return $total;
    }

    /**
     * Check if registration is closed for a session.
     *
     * A session is closed when either:
     *   - close_at ("Y-m-d H:i") has passed, or
     *   - the session start itself (date + optional time) has passed,
     *     so past terms are no longer selectable in the cart.
     */
    public function isRegistrationClosed($session)
    {
        $now = current_time('timestamp');

        if (!empty($session['close_at'])) {
            $close_ts = strtotime($session['close_at']);
            if ($close_ts && $now >= $close_ts) {
                return true;
            }
        }

        $start = $session['date'] ?? '';
        if ($start) {
            // A product may carry a top-level time AND slots, so collect both
            // and close only at the latest start; in slot mode there is no
            // top-level time and the session stays selectable until its latest
            // slot has started.
            $start_times = [];
            if (!empty($session['time'])) {
                $start_times[] = $session['time'];
            }
            if (!empty($session['slots']) && is_array($session['slots'])) {
                foreach ($session['slots'] as $slot) {
                    if (!empty($slot['time'])) {
                        $start_times[] = $slot['time'];
                    }
                }
            }

            $start_ts = strtotime($start . ' ' . ($start_times ? max($start_times) : ''));
            if ($start_ts && $now >= $start_ts) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build full options data with capacity, sold-out, and slot info.
     *
     * The checkout page and the switch AJAX both render the selection UI and
     * the JS config for the same product in one request; freeSeats() runs a
     * participant-ledger query per slot, so the second call is pure waste.
     * Memoized per product within the request: capacity only moves through
     * order/participant writes, which never happen between the two renders.
     */
    public function getOptionsWithStatus($product_id)
    {
        static $memo = [];
        if (isset($memo[$product_id])) {
            return $memo[$product_id];
        }

        $dates = $this->getSessionConfig($product_id);
        $result = [];

        foreach ($dates as $session) {
            $date = $session['date'] ?? '';
            if (!$date) {
                continue;
            }

            $has_slots = !empty($session['slots']) && is_array($session['slots']);
            $now = current_time('timestamp');
            $closed = $this->isRegistrationClosed($session);

            $date_label = \Alttag\Registrations\format_date($date, 'compact');
            if (!empty($session['label'])) {
                $date_label .= ' – ' . $session['label'];
            }

            $date_entry = [
                'date' => $date,
                'label' => $date_label,
                'closed' => $closed,
                'has_slots' => $has_slots,
                'slots' => [],
            ];

            if ($has_slots) {
                // Date with time slots
                $any_available = false;
                foreach ($session['slots'] as $slot) {
                    $time = $slot['time'] ?? '';
                    $slot_label = $slot['label'] ?? $time;
                    $capacity = absint($slot['capacity'] ?? 0);
                    $booked = $this->getBookingsCount($date, $time, $product_id);
                    $max_block = 0;
                    if (class_exists('Alttag\\Registrations\\GroupSeating')
                        && \Alttag\Registrations\GroupSeating::isEnabled($product_id)) {
                        // Match the admin overview: participant ledger plus fake
                        // seats, clamped separately inside every physical group.
                        $remaining = \Alttag\Registrations\GroupSeating::freeSeats(
                            $product_id,
                            (string) $time,
                            true
                        );
                        $booked = max(0, $capacity - $remaining);
                        // What one booking can still take: seating never splits
                        // a party, so the wave sum alone would oversell a family.
                        $max_block = \Alttag\Registrations\GroupSeating::largestFreeBlock(
                            $product_id,
                            (string) $time,
                            true
                        );
                    } else {
                        $remaining = $capacity > 0 ? $capacity - $booked : PHP_INT_MAX;
                    }
                    $sold_out = $capacity > 0 && $remaining <= 0;

                    // Slot-level start check: individual past slots stay closed
                    // even while the session (latest slot) is still selectable.
                    $slot_start_ts = strtotime($date . ' ' . $time);
                    $slot_closed = $closed || ($slot_start_ts && $now >= $slot_start_ts);

                    if (!$sold_out && !$slot_closed) {
                        $any_available = true;
                    }

                    $date_entry['slots'][] = [
                        'time' => $time,
                        'label' => $slot_label,
                        'capacity' => $capacity,
                        'booked' => $booked,
                        'remaining' => $capacity > 0 ? max(0, $remaining) : 0,
                        'max_block' => $max_block,
                        'sold_out' => $sold_out,
                        'disabled' => $sold_out || $slot_closed,
                    ];
                }
                $date_entry['sold_out'] = !$any_available;
                $date_entry['disabled'] = $closed || !$any_available;
            } else {
                // Date only (no slots). capacity=0 means unlimited.
                $capacity = absint($session['capacity'] ?? 0);
                $booked = $this->getBookingsCount($date, null, $product_id);
                $remaining = $capacity > 0 ? $capacity - $booked : PHP_INT_MAX;
                $date_entry['capacity'] = $capacity;
                $date_entry['booked'] = $booked;
                $date_entry['remaining'] = $capacity > 0 ? max(0, $remaining) : 0;
                $date_entry['sold_out'] = $capacity > 0 && $remaining <= 0;
                $date_entry['disabled'] = $closed || ($capacity > 0 && $remaining <= 0);
            }

            $result[] = $date_entry;
        }

        $memo[$product_id] = $result;
        return $result;
    }

    // =========================================================================
    // Checkout UI
    // =========================================================================

    public function renderCheckoutUI($product_id, $current_selections = [], $current_data = [], $product_label = '')
    {
        $options = $this->getOptionsWithStatus($product_id);
        if (empty($options)) {
            return;
        }

        $has_slots = false;
        foreach ($options as $opt) {
            if ($opt['has_slots']) {
                $has_slots = true;
                break;
            }
        }

        $session_label = apply_filters('alttag_registrations_session_field_label', __('Session date', 'alttag-registrations'));
        $slot_label = apply_filters('alttag_registrations_session_slot_label', __('Time slot', 'alttag-registrations'));
        $current_date = $current_selections[0] ?? '';
        $current_slot = is_string($current_data) ? $current_data : '';

        // Check if session is locked (pre-selected via URL parameter)
        // Lock value is the product_id - only locked for matching product
        $is_locked = false;
        if (function_exists('WC') && WC()->session) {
            $locked_pid = WC()->session->get('selected_session_locked');
            if (!empty($locked_pid) && (int) $locked_pid === (int) $product_id) {
                $is_locked = true;
            }
        }

        // Auto-select if only one available option
        $available = array_filter($options, function ($o) { return !$o['disabled']; });
        $only_one = count($available) === 1;
        $auto_select = ($only_one && empty($current_date));
        if ($auto_select) {
            $current_date = reset($available)['date'];
        }

        // Render as a hidden input (no dropdown) when:
        //   1. Locked via URL pre-select, OR
        //   2. The product has only one available option AND that option has no slots.
        // The latter case applies regardless of whether $current_date was already
        // set (e.g. from stale WC session state for another product) — when there
        // is only one available option, there is nothing to pick.
        if ($is_locked || ($only_one && empty($has_slots))) {
            // Force the only available option as the value
            if ($only_one) {
                $current_date = reset($available)['date'];
            }
            if (!empty($current_date)) {
                ?>
                <div class="session-selection session-selection--locked" id="session-selection-wrap">
                    <input type="hidden" name="selected_session[]" value="<?php echo esc_attr($current_date); ?>" />
                </div>
                <?php
                return;
            }
        }

        // Single date with slots: hide the date dropdown (only one choice),
        // but keep the slot picker visible. JS reads #selected_session to
        // resolve slots, so render a hidden input that mirrors the dropdown id.
        if ($only_one && $has_slots) {
            $current_date = reset($available)['date'];
            ?>
            <div
                class="session-selection session-selection--single-date"
                id="session-selection-wrap"
            >
                <input
                    type="hidden"
                    name="selected_session[]"
                    id="selected_session"
                    value="<?php echo esc_attr($current_date); ?>"
                />
                <p class="form-row form-row-wide session-slot-field" style="display:none;">
                    <label for="selected_session_slot">
                        <?php echo esc_html($slot_label); ?>
                        <abbr class="required" title="required">*</abbr>
                    </label>
                    <select name="selected_session_slot" id="selected_session_slot" class="select">
                        <option value=""><?php echo esc_html__('Select', 'alttag-registrations'); ?></option>
                    </select>
                </p>
            </div>
            <?php
            return;
        }

        ?>
        <div class="session-selection" id="session-selection-wrap">
            <p class="form-row form-row-wide">
                <label for="selected_session"><?php echo esc_html($session_label); ?> <abbr class="required" title="required">*</abbr></label>
                <select name="selected_session[]" id="selected_session" class="select">
                    <?php if (!$auto_select) : ?>
                        <option value=""><?php echo esc_html__('Select', 'alttag-registrations'); ?></option>
                    <?php endif; ?>
                    <?php foreach ($options as $opt) :
                        $text = $opt['label'];
                        if (!$opt['has_slots'] && !$opt['closed']) {
                            if ($opt['sold_out']) {
                                $text .= ' - ' . __('Sold out', 'alttag-registrations');
                            } elseif ($opt['capacity'] > 0) {
                                $text .= ' - ' . sprintf(
                                    /* translators: 1: free seats, 2: total seats */
                                    __('free seats %1$s/%2$s', 'alttag-registrations'),
                                    $opt['remaining'],
                                    $opt['capacity']
                                );
                            }
                        } elseif ($opt['closed']) {
                            $text .= ' - ' . __('Registration closed', 'alttag-registrations');
                        }
                    ?>
                        <option value="<?php echo esc_attr($opt['date']); ?>"
                            <?php disabled($opt['disabled']); ?>
                            <?php selected($current_date, $opt['date']); ?>
                        ><?php echo esc_html($text); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>

            <?php if ($has_slots) : ?>
            <p class="form-row form-row-wide session-slot-field" style="display:none;">
                <label for="selected_session_slot"><?php echo esc_html($slot_label); ?> <abbr class="required" title="required">*</abbr></label>
                <select name="selected_session_slot" id="selected_session_slot" class="select">
                    <option value=""><?php echo esc_html__('Select', 'alttag-registrations'); ?></option>
                </select>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function getJsConfig($product_id)
    {
        // The warning is assembled in the browser from numbers only known there,
        // so the declined noun cannot be picked in PHP. Ship every form keyed by
        // its count instead - the script looks the count up and never has to
        // know the locale's plural rule. 'more' covers everything past the map,
        // where each locale has settled on a single form.
        $seat_forms = [];
        $people_forms = [];
        foreach (array_merge(range(0, 30), [100]) as $n) {
            $key = $n === 100 ? 'more' : (string) $n;
            $seat_forms[$key] = _n('%d seat', '%d seats', $n, 'alttag-registrations');
            $people_forms[$key] = _nx(
                '%d person',
                '%d people',
                $n,
                'accusative, follows a preposition or verb',
                'alttag-registrations'
            );
        }

        return [
            'options' => $this->getOptionsWithStatus($product_id),
            'seat_forms' => $seat_forms,
            'people_forms' => $people_forms,
            'labels' => [
                'select' => __('Select', 'alttag-registrations'),
                'sold_out' => __('Sold out', 'alttag-registrations'),
                'available' => __('available', 'alttag-registrations'),
                /* translators: 1: free seats, 2: total seats */
                'seats' => __('free seats %1$s/%2$s', 'alttag-registrations'),
                'closed' => __('Registration closed', 'alttag-registrations'),
                /* translators: 1: seats free in the largest group */
                'together' => __('at most %1$s together in one group', 'alttag-registrations'),
                /* translators: 1: seats left in the slot, 2: seats free in the largest group, 3: people in the order */
                'party_too_big' => __('In this time %1$s remain free, but split across groups - at most %2$s can be together in one group. We always keep families in one group, so this time does not fit your %3$s. Please choose another time.', 'alttag-registrations'),
                /* translators: 1: seats left in the slot, 2: people in the order */
                'party_no_room' => __('Only %1$s is left in this time, so we cannot fit your %2$s. Please choose another time.', 'alttag-registrations'),
            ],
        ];
    }

    // =========================================================================
    // Validation
    // =========================================================================

    public function validate($selections, $product_id)
    {
        $date = is_array($selections) ? ($selections[0] ?? '') : $selections;
        if (empty($date)) {
            return new \WP_Error('session_required', __('Please select a session date.', 'alttag-registrations'));
        }

        $dates = $this->getSessionConfig($product_id);
        $found = null;
        foreach ($dates as $session) {
            if (($session['date'] ?? '') === $date) {
                $found = $session;
                break;
            }
        }

        if (!$found) {
            return new \WP_Error('session_invalid', __('Selected session is not valid.', 'alttag-registrations'));
        }

        if ($this->isRegistrationClosed($found)) {
            return new \WP_Error('session_closed', sprintf(
                __('Registration for %s is closed.', 'alttag-registrations'),
                \Alttag\Registrations\format_date($date, 'compact')
            ));
        }

        $has_slots = !empty($found['slots']) && is_array($found['slots']);

        if ($has_slots) {
            $slot = $_POST['selected_session_slot'] ?? '';
            if (empty($slot)) {
                return new \WP_Error('slot_required', __('Please select a time slot.', 'alttag-registrations'));
            }

            $slot_found = null;
            foreach ($found['slots'] as $s) {
                if (($s['time'] ?? '') === $slot) {
                    $slot_found = $s;
                    break;
                }
            }

            if (!$slot_found) {
                return new \WP_Error('slot_invalid', __('Selected time slot is not valid.', 'alttag-registrations'));
            }

            // Past slots are no longer selectable (same rule as the cart dropdown).
            $now = current_time('timestamp');
            $slot_start_ts = strtotime($date . ' ' . $slot);
            if ($slot_start_ts && $now >= $slot_start_ts) {
                return new \WP_Error('slot_closed', sprintf(
                    __('Registration for %s is closed.', 'alttag-registrations'),
                    $slot_found['label'] ?? $slot
                ));
            }

            $capacity = absint($slot_found['capacity'] ?? 0);
            if ($capacity > 0) {
                $booked = $this->getBookingsCount($date, $slot, $product_id);
                $pending = $this->getPendingPersonCount();
                if ($booked + $pending > $capacity) {
                    $remaining = max(0, $capacity - $booked);
                    return new \WP_Error('slot_full', sprintf(
                        /* translators: 1: slot label, 2: remaining seats, 3: requested */
                        __('Time slot %1$s only has %2$d seat(s) left, you requested %3$d.', 'alttag-registrations'),
                        $slot_found['label'] ?? $slot,
                        $remaining,
                        $pending
                    ));
                }
            }
        } else {
            $capacity = absint($found['capacity'] ?? 0);
            if ($capacity > 0) {
                $booked = $this->getBookingsCount($date, null, $product_id);
                $pending = $this->getPendingPersonCount();
                if ($booked + $pending > $capacity) {
                    $remaining = max(0, $capacity - $booked);
                    return new \WP_Error('session_full', sprintf(
                        /* translators: 1: date, 2: remaining seats, 3: requested */
                        __('Session on %1$s only has %2$d seat(s) left, you requested %3$d.', 'alttag-registrations'),
                        \Alttag\Registrations\format_date($date, 'compact'),
                        $remaining,
                        $pending
                    ));
                }
            }
        }

        return true;
    }

    /**
     * Total persons being registered in the current cart, derived from
     * ParticipantTypes selection. Falls back to 1 when participant types
     * aren't used.
     */
    private function getPendingPersonCount(): int
    {
        if (!function_exists('WC') || !WC()->session) {
            return 1;
        }
        $data = WC()->session->get('selected_participant_types_data', []);
        if (!is_array($data) || empty($data)) {
            return 1;
        }
        $total = array_sum(array_map('intval', $data));
        return $total > 0 ? $total : 1;
    }

    // =========================================================================
    // Display formatting
    // =========================================================================

    public function formatSelections($selections, $selection_data, $product_id)
    {
        $date = is_array($selections) ? ($selections[0] ?? '') : $selections;
        if (empty($date)) {
            return '';
        }

        $formatted = \Alttag\Registrations\format_date($date);
        $slot = is_string($selection_data) ? $selection_data : '';

        // Look up session config for label (e.g. city) and slot info
        $dates = $this->getSessionConfig($product_id);
        $session_label = '';
        $slot_label = '';

        foreach ($dates as $session) {
            if (($session['date'] ?? '') !== $date) {
                continue;
            }
            $session_label = $session['label'] ?? '';
            if ($slot && !empty($session['slots'])) {
                foreach ($session['slots'] as $s) {
                    if (($s['time'] ?? '') === $slot) {
                        $slot_label = $s['label'] ?? $slot;
                        break;
                    }
                }
            }
            break;
        }

        if ($slot_label) {
            $formatted .= ', ' . $slot_label;
        }
        // The venue is appended only when the product does not carry it in its
        // own setting. If it does, the product name already says where it is and
        // repeating the full address here just makes the cart noisy. The label
        // itself stays untouched, {session_location} on the ticket reads it.
        if ($session_label && !$this->hasOwnVenue($product_id)) {
            $formatted .= ', ' . $session_label;
        }

        return $formatted;
    }

    /** Does the product define its venue outside the session label? */
    private function hasOwnVenue($product_id): bool
    {
        $venue = \Alttag\Registrations\Settings::getValue('general.venue', (int) $product_id);

        return is_string($venue) && trim($venue) !== '';
    }
}
