<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Grouped seating: places a booking into a station (A, B, C) inside the wave the
 * customer chose.
 *
 * Model:
 *   - A wave is a TIME SLOT on the product, configured in `_session_dates` like
 *     any other session slot. The customer picks the wave, and the slot's own
 *     capacity governs how many fit into it.
 *   - Inside a wave there are stations A, B, C. The customer does not pick one;
 *     we assign it, and every booking stays together in a single station because
 *     a station is a physical place in the venue.
 *
 * Placement rule: scan the stations in order and take the first one that has room
 * for the WHOLE booking. A station with one seat left is therefore skipped by a
 * couple, and that seat stays open for a later single booking.
 *
 * Capacity lives in the time slots, not here and not in pricing tiers, so there
 * is exactly one number to maintain per wave.
 */
class GroupSeating
{
    public const EXPRESS_CAPACITY_ACTION = 'alttag_group_seating_capacity';

    /** Product opt-in flag. */
    public const PRODUCT_ENABLED = '_alttag_group_seating_enabled';

    /** How many stations each wave is split into (default DEFAULT_GROUPS). */
    public const PRODUCT_GROUPS = '_alttag_group_seating_groups';

    /** Stored on the order (source of truth) and mirrored onto participants. */
    public const ORDER_GROUP = '_alttag_group';
    public const ORDER_WAVE = '_alttag_wave';
    public const META_GROUP = 'seating_group';
    public const META_WAVE = 'seating_wave';
    public const META_TIME = 'seating_time';

    public const DEFAULT_GROUPS = 3;

    /** Order statuses that release a seat again. */
    private const RELEASED_STATUSES = ['cancelled', 'refunded', 'failed', 'trash', 'archived'];

    public function registerHooks()
    {
        add_action('woocommerce_checkout_order_processed', [$this, 'validateCreatedOrder'], 10, 3);
        add_action('woocommerce_checkout_order_processed', [$this, 'assignForOrder'], 20, 3);
        add_action('woocommerce_checkout_process', [$this, 'checkoutGate'], 30);
        add_action('woocommerce_store_api_checkout_update_order_meta', [$this, 'validateStoreApiCheckout'], 10);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'assignStoreApiOrder'], 20);
        add_action('wp_ajax_' . self::EXPRESS_CAPACITY_ACTION, [$this, 'ajaxCapacityCheck']);
        add_action('wp_ajax_nopriv_' . self::EXPRESS_CAPACITY_ACTION, [$this, 'ajaxCapacityCheck']);
        add_action('wp_enqueue_scripts', [$this, 'localizeExpressCapacityGuard'], 20);
        add_action('alttag_registrations_participant_create', [$this, 'inheritOnParticipant'], 25, 2);
        add_action('woocommerce_product_options_inventory_product_data', [$this, 'productFields']);
        add_action('woocommerce_process_product_meta', [$this, 'saveProductFields']);

        // Occupancy is counted from ORDERS, so removing a participant would
        // otherwise free no seat. Both hooks run through the same recalculation:
        // trashing already hides the person from every roster, and a later
        // permanent delete simply recounts and finds nothing left to release.
        add_action('trashed_post', [$this, 'participantRemoved']);
        add_action('before_delete_post', [$this, 'participantRemoved']);
    }

    // ---------------------------------------------------------------- config

    public static function isEnabled($product_id): bool
    {
        $product_id = (int) $product_id;

        return $product_id > 0
            && get_post_meta($product_id, self::PRODUCT_ENABLED, true) === 'yes';
    }

    /**
     * Every product that uses grouped seating.
     *
     * @return int[]
     */
    public static function enabledProducts(): array
    {
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'private'],
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_query' => [
                ['key' => self::PRODUCT_ENABLED, 'value' => 'yes'],
            ],
        ]);

        return array_map('intval', (array) $ids);
    }

    /** Number of stations per wave. */
    public static function groupCount($product_id): int
    {
        $n = (int) get_post_meta((int) $product_id, self::PRODUCT_GROUPS, true);
        if ($n < 1) {
            $n = self::DEFAULT_GROUPS;
        }

        return (int) apply_filters(
            'alttag_registrations_group_seating_group_count',
            $n,
            (int) $product_id
        );
    }

    /**
     * Station letters for a wave: A, B, C, … by default.
     *
     * $wave is passed through to the filter so a site can number stations
     * continuously across waves (wave 1 = A,B,C; wave 2 = D,E,F; …) instead of
     * restarting the alphabet in every wave. Callers that know which wave they
     * are working on MUST pass it, otherwise the ledger for wave 2 would be
     * keyed by wave 1's letters and every seat would read as free.
     *
     * @return string[]
     */
    public static function groups($product_id, string $wave = ''): array
    {
        $groups = [];
        for ($i = 0; $i < self::groupCount($product_id); $i++) {
            $groups[] = chr(65 + $i);
        }

        return (array) apply_filters(
            'alttag_registrations_group_seating_groups',
            $groups,
            (int) $product_id,
            $wave
        );
    }

    /**
     * Waves = the product's time slots.
     *
     * @return array<string,array{label:string,capacity:int,date:string}>
     *         keyed by slot time, which is what the checkout posts back
     */
    public static function waves($product_id): array
    {
        $dates = get_post_meta((int) $product_id, '_session_dates', true);
        if (!is_array($dates)) {
            return [];
        }

        $waves = [];
        foreach ($dates as $entry) {
            $date = (string) ($entry['date'] ?? '');
            foreach ((array) ($entry['slots'] ?? []) as $slot) {
                $time = (string) ($slot['time'] ?? '');
                if ($time === '') {
                    continue;
                }
                $waves[$time] = [
                    'label' => (string) ($slot['label'] ?? $time),
                    'capacity' => (int) ($slot['capacity'] ?? 0),
                    'date' => $date,
                ];
            }
        }

        return $waves;
    }

    /** Seats per station inside a wave: the slot capacity split between stations. */
    public static function capacity($product_id, string $wave = ''): int
    {
        $waves = self::waves($product_id);
        $slot_capacity = 0;

        if ($wave !== '' && isset($waves[$wave])) {
            $slot_capacity = (int) $waves[$wave]['capacity'];
        } elseif ($waves) {
            $slot_capacity = (int) reset($waves)['capacity'];
        }

        $groups = max(1, self::groupCount($product_id));
        $per_group = $slot_capacity > 0 ? (int) floor($slot_capacity / $groups) : 0;

        return (int) apply_filters(
            'alttag_registrations_group_seating_capacity',
            $per_group,
            (int) $product_id,
            $wave
        );
    }

    // ------------------------------------------------- placement (pure logic)

    /**
     * First station that fits the whole party, or null when nothing fits.
     *
     * Pure and WP-free on purpose: the rule that decides where people sit must be
     * verifiable without a database.
     *
     * @param array<string,int> $ledger station => seats already taken
     * @param string[] $groups
     */
    public static function place(array $ledger, int $party, int $capacity, array $groups): ?string
    {
        if ($party < 1) {
            $party = 1;
        }

        foreach ($groups as $group) {
            $taken = isset($ledger[$group]) ? (int) $ledger[$group] : 0;
            if (($capacity - $taken) >= $party) {
                return $group;
            }
        }

        return null;
    }

    // ------------------------------------------------------------- accounting

    /**
     * Seats taken per station inside one wave, counted from orders.
     *
     * This is the capacity policing source (checkout gate and assignment): at
     * checkout no participant post exists yet, and an unpaid order has only
     * materialised its buyer, so orders are the only complete count there.
     * Customer/admin availability displays read `participantLedger()` instead.
     *
     * @return array<string,int>
     */
    public static function ledger($product_id, string $wave, int $exclude_order_id = 0): array
    {
        $ledger = array_fill_keys(self::groups($product_id, $wave), 0);

        foreach (self::seatedOrders($product_id) as $row) {
            if ((int) $row['order_id'] === $exclude_order_id) {
                continue;
            }
            if ($row['wave'] !== $wave) {
                continue;
            }
            if (array_key_exists($row['group'], $ledger)) {
                $ledger[$row['group']] += (int) $row['people'];
            }
        }

        return $ledger;
    }

    /**
     * Seats taken per station inside one wave, counted from PARTICIPANTS.
     *
     * What the admin screens ask is who is really sitting at a station, and the
     * people at a station are the participant posts, not the order rows: when
     * the last participant of an order is trashed there is no roster evidence
     * for an empty party, so an order count can keep a seat nobody occupies.
     *
     * Orders still decide whether a booking is live at all, because cancelling
     * or refunding an order releases its seats while its participants stay.
     *
     * @return array<string,int>
     */
    public static function participantLedger($product_id, string $wave): array
    {
        $ledger = array_fill_keys(self::groups($product_id, $wave), 0);

        foreach (self::seatedParticipants($product_id) as $row) {
            if ($row['wave'] !== $wave) {
                continue;
            }
            if (array_key_exists($row['group'], $ledger)) {
                $ledger[$row['group']] += (int) $row['people'];
            }
        }

        return $ledger;
    }

    /**
     * Participants holding a seat for this product.
     *
     * Seats, not posts: one participant post can carry a whole family in its
     * `selected_participant_types_data` map, the same rule `liveParticipants()`
     * applies to a single order.
     *
     * @return array<int,array{participant_id:int,order_id:int,group:string,wave:string,people:int}>
     */
    public static function seatedParticipants($product_id): array
    {
        $product_id = (int) $product_id;

        // Whose booking still holds a seat. A participant whose order was
        // cancelled, refunded or deleted is not seated any more, even though
        // the post survives the status change.
        $live_orders = [];
        foreach (self::seatedOrders($product_id) as $order_row) {
            $live_orders[(int) $order_row['order_id']] = true;
        }

        $participants = get_posts([
            'post_type' => 'participant',
            'post_status' => 'any',
            'numberposts' => -1,
            'meta_query' => [
                ['key' => 'product_id', 'value' => $product_id],
                ['key' => self::META_GROUP, 'compare' => 'EXISTS'],
            ],
        ]);

        $rows = [];
        foreach ($participants as $participant) {
            if (!$participant instanceof \WP_Post) {
                continue;
            }
            if ($participant->post_status === 'trash' || $participant->post_status === 'auto-draft') {
                continue;
            }

            $group = (string) get_post_meta($participant->ID, self::META_GROUP, true);
            if ($group === '') {
                continue;
            }

            // An imported participant with no order of its own is seated on its
            // own account, so only an order that exists has to still be live.
            $order_id = (int) get_post_meta($participant->ID, 'order_id', true);
            if ($order_id && !isset($live_orders[$order_id])) {
                continue;
            }

            $rows[] = [
                'participant_id' => (int) $participant->ID,
                'order_id' => $order_id,
                'group' => $group,
                'wave' => (string) get_post_meta($participant->ID, self::META_WAVE, true),
                'people' => self::participantSeatCount($participant->ID),
            ];
        }

        return $rows;
    }

    /**
     * Orders holding a seat for this product.
     *
     * @return array<int,array{order_id:int,group:string,wave:string,people:int,status:string}>
     */
    public static function seatedOrders($product_id): array
    {
        $orders = wc_get_orders([
            'limit' => -1,
            'status' => array_values(array_diff(
                array_keys(wc_get_order_statuses()),
                array_map(static function ($s) {
                    return 'wc-' . $s;
                }, self::RELEASED_STATUSES)
            )),
            'meta_key' => self::ORDER_GROUP,
            'meta_compare' => 'EXISTS',
            'return' => 'objects',
        ]);

        $rows = [];
        foreach ($orders as $order) {
            if (!$order instanceof \WC_Order) {
                continue;
            }
            $group = (string) $order->get_meta(self::ORDER_GROUP);
            if ($group === '') {
                continue;
            }
            $people = self::partySize($order, $product_id);
            if ($people < 1) {
                continue; // order does not contain this product
            }
            $rows[] = [
                'order_id' => $order->get_id(),
                'group' => $group,
                'wave' => (string) $order->get_meta(self::ORDER_WAVE),
                'people' => $people,
                'status' => $order->get_status(),
            ];
        }

        return $rows;
    }

    /**
     * How many people an order books for this product.
     *
     * Participant-type quantities win when the product uses them, because one
     * line item can be "1 adult + 1 child".
     */
    public static function partySize($order, $product_id): int
    {
        if (!$order instanceof \WC_Order) {
            return 0;
        }

        $product_id = (int) $product_id;
        $people = 0;

        foreach ($order->get_items() as $item) {
            if ((int) $item->get_product_id() !== $product_id) {
                continue;
            }

            $types = $item->get_meta('selected_participant_types_data');
            if (!is_array($types)) {
                $types = $item->get_meta('_selected_participant_types_data');
            }
            if (is_array($types)) {
                $people += array_sum(array_map('intval', $types));
                continue;
            }

            $people += max(1, (int) $item->get_quantity());
        }

        return (int) apply_filters(
            'alttag_registrations_group_seating_party_size',
            $people,
            $order,
            $product_id
        );
    }

    /** The wave (slot time) an order booked. */
    public static function orderWave($order, $product_id): string
    {
        if (!$order instanceof \WC_Order) {
            return '';
        }

        $waves = self::waves($product_id);

        // The slot posted at checkout. Guard against the selection-data array
        // that shares this meta key when a product has no slots configured.
        foreach (['selected_session_slot'] as $key) {
            $raw = $order->get_meta($key);
            if (is_string($raw) && $raw !== '' && isset($waves[$raw])) {
                return $raw;
            }
        }

        foreach ($order->get_items() as $item) {
            $raw = $item->get_meta('selected_session_slot');
            if (is_string($raw) && $raw !== '' && isset($waves[$raw])) {
                return $raw;
            }
        }

        // Single-wave products need no choice.
        if (count($waves) === 1) {
            return (string) array_key_first($waves);
        }

        return '';
    }

    // -------------------------------------------------------------- checkout

    /** Block checkout when a booking cannot fit into any station of its wave. */
    public function checkoutGate()
    {
        foreach ($this->cartCapacityErrors() as $message) {
            if (!function_exists('wc_has_notice') || !wc_has_notice($message, 'error')) {
                wc_add_notice($message, 'error');
            }
        }
    }

    /** Fresh server-side capacity check used before Stripe opens a wallet sheet. */
    public function ajaxCapacityCheck()
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::EXPRESS_CAPACITY_ACTION)) {
            wp_send_json_error([
                'message' => __('Expired request, please reload the page.', 'alttag-registrations'),
            ], 400);
        }

        $errors = $this->cartCapacityErrors();
        wp_send_json_success([
            'valid' => !$errors,
            'message' => $errors ? reset($errors) : '',
        ]);
    }

    /** Give the shared Stripe guard this module's read-only preflight endpoint. */
    public function localizeExpressCapacityGuard()
    {
        $handle = 'alttag-registrations-express-checkout-guard';
        // Follow the script itself (enqueued on product/cart/checkout) instead
        // of re-testing is_checkout() here — the guard needs its capacity
        // config wherever the wallet buttons render.
        if (!wp_script_is($handle, 'enqueued') && !wp_script_is($handle, 'registered')) {
            return;
        }

        wp_localize_script($handle, 'alttagExpressCapacity', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::EXPRESS_CAPACITY_ACTION,
            'nonce' => wp_create_nonce(self::EXPRESS_CAPACITY_ACTION),
            'requestError' => __('Expired request, please reload the page.', 'alttag-registrations'),
        ]);
    }

    /**
     * Reject a Store API checkout before its draft order reaches payment.
     *
     * Store API does not dispatch `woocommerce_checkout_process`; core explicitly
     * allows this update-order hook to abort checkout by throwing an exception.
     */
    public function validateStoreApiCheckout($order)
    {
        $errors = $this->cartCapacityErrors();
        if ($errors) {
            throw new \Exception(reset($errors));
        }
    }

    /**
     * Final race-safe validation after classic checkout built the complete order,
     * but before WooCommerce hands it to the payment gateway.
     */
    public function validateCreatedOrder($order_id, $posted_data = [], $order = null)
    {
        $order = $order instanceof \WC_Order ? $order : wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }

        foreach ($order->get_items() as $item) {
            $product_id = (int) $item->get_product_id();
            if (!self::isEnabled($product_id) || !self::waves($product_id)) {
                continue;
            }

            $wave = self::orderWave($order, $product_id);
            if ($wave === '') {
                continue;
            }

            $party = self::partySize($order, $product_id);
            if (self::place(
                self::ledger($product_id, $wave, $order->get_id()),
                $party,
                self::capacity($product_id, $wave),
                self::groups($product_id, $wave)
            ) === null) {
                throw new \Exception(self::capacityErrorMessage(
                    $party,
                    self::freeSeats($product_id, $wave),
                    self::largestFreeBlock($product_id, $wave)
                ));
            }
        }
    }

    /** Validate and assign the Store API order before its payment step. */
    public function assignStoreApiOrder($order)
    {
        if (!$order instanceof \WC_Order) {
            return;
        }

        $this->validateCreatedOrder($order->get_id(), [], $order);
        $this->assignForOrder($order->get_id(), [], $order);
    }

    /** @return string[] Customer-facing capacity errors for the current cart. */
    private function cartCapacityErrors(): array
    {
        $posted_slot = self::checkoutValue('selected_session_slot', '');
        $posted_slot = is_string($posted_slot) ? sanitize_text_field($posted_slot) : '';
        $errors = [];

        $lines = [];
        if (function_exists('WC') && WC()->cart) {
            $lines = array_values(WC()->cart->get_cart());
        }
        if (!$lines) {
            // Wallet buttons also render on the single product page, where the
            // cart is still empty: the party selection travels in the serialized
            // product form (post_data), keyed by add-to-cart / product_id.
            $pid = isset($_POST['add-to-cart']) ? (int) wp_unslash($_POST['add-to-cart']) : 0;
            if ($pid <= 0 && isset($_POST['product_id'])) {
                $pid = (int) wp_unslash($_POST['product_id']);
            }
            if ($pid <= 0 && isset($_POST['post_data']) && is_string($_POST['post_data'])) {
                parse_str(wp_unslash($_POST['post_data']), $posted_form);
                $pid = (int) ($posted_form['add-to-cart'] ?? $posted_form['product_id'] ?? 0);
            }
            if ($pid > 0) {
                $lines[] = ['product_id' => $pid, 'quantity' => 1];
            }
        }

        foreach ($lines as $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            if (!$pid || !self::isEnabled($pid)) {
                continue;
            }

            $waves = self::waves($pid);
            if (!$waves) {
                continue; // no slots configured yet, nothing to police
            }

            $wave = isset($waves[$posted_slot])
                ? $posted_slot
                : (count($waves) === 1 ? (string) array_key_first($waves) : '');
            if ($wave === '') {
                continue; // the session selector validates the choice itself
            }

            // SelectionManager persists counters in the WC session, not in the
            // cart line. Quantity is normally one even when four people were
            // selected, so the session/request must win over that fallback.
            $types = self::checkoutValue(
                'selected_participant_types_data',
                $line['selected_participant_types_data'] ?? null
            );
            $party = is_array($types) && $types
                ? array_sum(array_map('intval', $types))
                : max(1, (int) ($line['quantity'] ?? 1));

            $group = self::place(
                self::ledger($pid, $wave),
                $party,
                self::capacity($pid, $wave),
                self::groups($pid, $wave)
            );
            if ($group !== null) {
                continue;
            }

            $errors[] = self::capacityErrorMessage(
                $party,
                self::freeSeats($pid, $wave),
                self::largestFreeBlock($pid, $wave)
            );
        }

        return $errors;
    }

    /** Read checkout data from the direct request, serialized AJAX data, or WC session. */
    private static function checkoutValue(string $key, $default = null)
    {
        if (array_key_exists($key, $_POST)) {
            return wp_unslash($_POST[$key]);
        }
        if (isset($_POST['post_data']) && is_string($_POST['post_data'])) {
            parse_str(wp_unslash($_POST['post_data']), $posted);
            if (array_key_exists($key, $posted)) {
                return $posted[$key];
            }
        }
        if (function_exists('WC') && WC()->session) {
            return WC()->session->get($key, $default);
        }

        return $default;
    }

    private static function capacityErrorMessage(int $party, int $free, ?int $max_block = null): string
    {
        if ($free <= 0) {
            return __('This time slot is sold out. Please choose another one.', 'alttag-registrations');
        }

        // Fragmented wave: the sum on its own reads as a slot that fits, so say
        // what one group can still take before the customer tries again.
        // The counted nouns travel inside the placeholders: Slovak declines them
        // by the number, which a bare %d in the sentence cannot express.
        $free_seats = sprintf(_n('%d seat', '%d seats', $free, 'alttag-registrations'), $free);
        $block_seats = sprintf(_n('%d seat', '%d seats', $max_block ?? 0, 'alttag-registrations'), $max_block ?? 0);
        $people = sprintf(
            _nx('%d person', '%d people', $party, 'accusative, follows a preposition or verb', 'alttag-registrations'),
            $party
        );

        if ($max_block !== null && $max_block > 0 && $max_block < $free && $max_block < $party) {
            return sprintf(
                /* translators: 1: seats left in the wave, 2: seats left in the largest group, 3: people in the order */
                __('In this time %1$s remain free, but split across groups - at most %2$s can be together in one group. We always keep families in one group, so this time does not fit your %3$s. Please choose another time.', 'alttag-registrations'),
                $free_seats,
                $block_seats,
                $people
            );
        }

        if ($max_block !== null && $max_block > 0 && $max_block >= $free && $max_block < $party) {
            return sprintf(
                /* translators: 1: seats left in the slot, 2: people in the order */
                __('Only %1$s is left in this time, so we cannot fit your %2$s. Please choose another time.', 'alttag-registrations'),
                $free_seats,
                $people
            );
        }

        return sprintf(
            /* translators: 1: people in the order, 2: seats left in the wave */
            __('We can no longer keep %1$s together in this time slot - %2$s remain, but only separately. Please choose another time or register the people separately.', 'alttag-registrations'),
            $people,
            $free_seats
        );
    }

    /** Participant-ledger free seats; optionally include display-only fake seats. */
    public static function freeSeats($product_id, string $wave = '', bool $include_fake = false): int
    {
        $waves = $wave !== '' ? [$wave => true] : self::waves($product_id);
        $fake = $include_fake ? self::fakeSeats($product_id) : [];
        $free = 0;

        foreach (array_keys($waves) as $w) {
            $capacity = self::capacity($product_id, (string) $w);
            foreach (self::participantLedger($product_id, (string) $w) as $group => $taken) {
                $fake_seats = (int) ($fake[$w . '|' . $group] ?? 0);
                $free += max(0, $capacity - (int) $taken - $fake_seats);
            }
        }

        return $free;
    }

    /**
     * Biggest party that still fits inside ONE group of the wave.
     *
     * freeSeats() sums what is left across every group, which reads as room to
     * a family that can never use it: place() never splits a party, so what a
     * single booking can actually take is the largest block. Counted from the
     * same source as freeSeats(), so the two numbers can be shown side by side.
     */
    public static function largestFreeBlock($product_id, string $wave, bool $include_fake = false): int
    {
        $fake = $include_fake ? self::fakeSeats($product_id) : [];
        $capacity = self::capacity($product_id, $wave);
        $largest = 0;

        foreach (self::participantLedger($product_id, $wave) as $group => $taken) {
            $fake_seats = (int) ($fake[$wave . '|' . $group] ?? 0);
            $largest = max($largest, $capacity - (int) $taken - $fake_seats);
        }

        return max(0, $largest);
    }

    /** @return array<string,int> Artificial display-only seats keyed by "wave|group". */
    private static function fakeSeats($product_id): array
    {
        $all = get_option('alttag_seating_fake_seats', []);
        $map = is_array($all) && isset($all[$product_id]) && is_array($all[$product_id])
            ? $all[$product_id]
            : [];

        return array_map('intval', $map);
    }

    // ------------------------------------------------------------ assignment

    /** Assign a station when the order is created. */
    public function assignForOrder($order_id, $posted_data = [], $order = null)
    {
        $order = $order instanceof \WC_Order ? $order : wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }

        if ((string) $order->get_meta(self::ORDER_GROUP) !== '') {
            return; // already placed, never move a booking silently
        }

        foreach ($order->get_items() as $item) {
            $product_id = (int) $item->get_product_id();
            if (!self::isEnabled($product_id) || !self::waves($product_id)) {
                continue;
            }

            $wave = self::orderWave($order, $product_id);
            $party = self::partySize($order, $product_id);

            if ($wave === '') {
                $order->add_order_note('Grouped seating: no time slot on the order, station not assigned.');
                $order->save();

                return;
            }

            $group = self::place(
                self::ledger($product_id, $wave, $order->get_id()),
                $party,
                self::capacity($product_id, $wave),
                self::groups($product_id, $wave)
            );

            if ($group === null) {
                $order->add_order_note(sprintf(
                    'Grouped seating: no station in wave %s could fit %d people. Checkout rejected.',
                    $wave,
                    $party
                ));
                $order->save();

                throw new \Exception(self::capacityErrorMessage(
                    $party,
                    self::freeSeats($product_id, $wave),
                    self::largestFreeBlock($product_id, $wave)
                ));
            }

            $order->update_meta_data(self::ORDER_GROUP, $group);
            $order->update_meta_data(self::ORDER_WAVE, $wave);
            $order->add_order_note(sprintf(
                'Grouped seating: %d people placed in station %s of wave %s.',
                $party,
                $group,
                self::waveLabel($product_id, $wave)
            ));
            $order->save();

            do_action('alttag_registrations_group_assigned', $order, $group, $product_id, $party, $wave);

            return; // one station per order
        }
    }

    /**
     * Move a whole booking to another station or wave.
     *
     * The occupancy is counted per ORDER, because a booking always sits together
     * in one physical station. Writing only the participant would leave the seats
     * held by the old station and show the new one as empty, so both the order and
     * every participant of that order are moved together.
     *
     * Capacity is deliberately NOT enforced here: this is the manual override an
     * admin uses when the automatic placement needs correcting, and the admin can
     * see the occupancy next to every station while deciding.
     *
     * @param int    $order_id
     * @param string $group
     * @param string $wave
     * @return bool true when the booking was moved
     */
    public static function moveOrder($order_id, string $group, string $wave): bool
    {
        $order = wc_get_order((int) $order_id);
        if (!$order instanceof \WC_Order) {
            return false;
        }

        $product_id = 0;
        foreach ($order->get_items() as $item) {
            $candidate = (int) $item->get_product_id();
            if (self::isEnabled($candidate)) {
                $product_id = $candidate;
                break;
            }
        }
        if (!$product_id) {
            return false;
        }

        $from_group = (string) $order->get_meta(self::ORDER_GROUP);
        $from_wave = (string) $order->get_meta(self::ORDER_WAVE);

        if ($group === '') {
            $order->delete_meta_data(self::ORDER_GROUP);
            $order->delete_meta_data(self::ORDER_WAVE);
        } else {
            $order->update_meta_data(self::ORDER_GROUP, $group);
            $order->update_meta_data(self::ORDER_WAVE, $wave);
            // Keep the booking slot (ticket header, emails, thank-you read it)
            // in sync with the seating wave on admin moves.
            $order->update_meta_data('selected_session_slot', $wave);
        }

        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $order->add_order_note(sprintf(
            'Grouped seating: moved from %s / %s to %s / %s%s.',
            $from_group !== '' ? $from_group : 'unassigned',
            $from_wave !== '' ? $from_wave : 'no wave',
            $group !== '' ? $group : 'unassigned',
            $wave !== '' ? $wave : 'no wave',
            $user && $user->exists() ? ' by ' . $user->user_login : ''
        ));
        $order->save();

        foreach (self::orderParticipants((int) $order_id) as $participant_id) {
            if ($group === '') {
                delete_post_meta($participant_id, self::META_GROUP);
                delete_post_meta($participant_id, self::META_WAVE);
                delete_post_meta($participant_id, self::META_TIME);
                continue;
            }
            self::writeParticipantGroup($participant_id, $product_id, $group, $wave);
        }

        do_action('alttag_registrations_group_moved', $order, $group, $wave, $product_id);

        return true;
    }

    // --------------------------------------------------------- reconciliation

    /**
     * Bring every seated order back in line with the product's current stations.
     *
     * Needed because the station letters are produced by a filter, so a site can
     * change how they are numbered (per wave A,B,C / A,B,C versus continuous
     * A,B,C / D,E,F) after bookings already exist. Orders keep the letter they
     * were given, and `ledger()` only counts letters that still exist, so those
     * bookings silently stop occupying a seat.
     *
     * The remap is by POSITION inside the wave: the old letter's alphabet index
     * is the table position the party physically sat at, so index 2 of the wave
     * stays index 2 of the wave whatever the new letter is called.
     *
     * Nothing valid is ever moved, so running this twice is a no-op.
     *
     * @return array<int,array{checked:int,parties_restored:int,groups_fixed:int,waves_fixed:int,participants_updated:int,skipped:int,unresolved:array<int,string>}>
     *         keyed by product id
     */
    public static function reconcile(): array
    {
        $report = [];
        foreach (self::enabledProducts() as $product_id) {
            $report[$product_id] = [
                'checked' => 0,
                'parties_restored' => 0,
                'groups_fixed' => 0,
                'waves_fixed' => 0,
                'participants_updated' => 0,
                'skipped' => 0,
                'unresolved' => [],
            ];
        }
        if (!$report) {
            return $report;
        }

        // Released orders are included in the query on purpose: they must be
        // reported as skipped rather than look like they were checked.
        $orders = wc_get_orders([
            'limit' => -1,
            'status' => 'any',
            'meta_key' => self::ORDER_GROUP,
            'meta_compare' => 'EXISTS',
            'return' => 'objects',
        ]);

        foreach ($orders as $order) {
            if (!$order instanceof \WC_Order) {
                continue;
            }

            $product_id = 0;
            foreach ($order->get_items() as $item) {
                $candidate = (int) $item->get_product_id();
                if (isset($report[$candidate])) {
                    $product_id = $candidate;
                    break;
                }
            }
            if (!$product_id) {
                continue;
            }

            if (in_array($order->get_status(), self::RELEASED_STATUSES, true)) {
                $report[$product_id]['skipped']++;
                continue;
            }

            $order_id = $order->get_id();
            $report[$product_id]['checked']++;

            $participants = self::orderParticipants($order_id);

            // Party first: the booked head count is what the ledger sums, so a
            // line item that lost seats is healed before the letters are judged.
            // Grow-only, and only from the participants. Reconcile must never
            // take a seat away, or a stale count here would quietly unbook
            // people the letters are then recalculated around.
            $restored = self::restoreParty($order, $product_id, $participants);
            if ($restored['restored']) {
                $report[$product_id]['parties_restored']++;
            } elseif ($restored['reason'] !== '') {
                $report[$product_id]['unresolved'][$order_id] = $restored['reason'];
            }

            $waves = self::waves($product_id);
            $from_wave = (string) $order->get_meta(self::ORDER_WAVE);
            $from_group = (string) $order->get_meta(self::ORDER_GROUP);
            $wave = $from_wave;
            $group = $from_group;

            if (!isset($waves[$wave])) {
                $wave = self::resolveWave($product_id, $from_wave, $participants);
            }

            if ($wave === '' || !isset($waves[$wave])) {
                $report[$product_id]['unresolved'][$order_id] = sprintf(
                    /* translators: %s: the time slot stored on the order */
                    __('Time slot "%s" no longer exists on the product and could not be matched.', 'alttag-registrations'),
                    $from_wave !== '' ? $from_wave : '-'
                );
                continue;
            }

            $groups = self::groups($product_id, $wave);
            if (!in_array($group, $groups, true)) {
                $index = strlen($group) === 1 ? ord($group) - 65 : -1;
                if ($index < 0 || !isset($groups[$index])) {
                    $report[$product_id]['unresolved'][$order_id] = sprintf(
                        /* translators: 1: station letter on the order, 2: time slot */
                        __('Station "%1$s" does not exist in time slot "%2$s" and no station holds the same position.', 'alttag-registrations'),
                        $group !== '' ? $group : '-',
                        self::waveLabel($product_id, $wave)
                    );
                    continue;
                }
                $group = (string) $groups[$index];
            }

            if ($group !== $from_group) {
                $report[$product_id]['groups_fixed']++;
            }
            if ($wave !== $from_wave) {
                $report[$product_id]['waves_fixed']++;
            }

            if ($group !== $from_group || $wave !== $from_wave) {
                $order->update_meta_data(self::ORDER_GROUP, $group);
                $order->update_meta_data(self::ORDER_WAVE, $wave);
                $order->add_order_note(sprintf(
                    'Grouped seating: recalculated from %s / %s to %s / %s.',
                    $from_group !== '' ? $from_group : 'unassigned',
                    $from_wave !== '' ? $from_wave : 'no wave',
                    $group,
                    $wave
                ));
                $order->save();
            }

            // Always re-mirror: the participant meta feeds the participant list
            // and the check-in verify page, and an admin edit can have left it
            // behind even when the order itself was already correct.
            $label = self::waveLabel($product_id, $wave);
            foreach ($participants as $participant_id) {
                $stale = (string) get_post_meta($participant_id, self::META_GROUP, true) !== $group
                    || (string) get_post_meta($participant_id, self::META_WAVE, true) !== $wave
                    || (string) get_post_meta($participant_id, self::META_TIME, true) !== $label;

                self::writeParticipantGroup($participant_id, $product_id, $group, $wave);

                if ($stale) {
                    $report[$product_id]['participants_updated']++;
                }
            }
        }

        return $report;
    }

    /**
     * Give a line item back the seats it holds according to the participants.
     *
     * One participant post can stand for a whole family: its
     * `selected_participant_types_data` map is the booked mix, so counting
     * POSTS reports a party of five as a party of one. A recount that made that
     * mistake shrank the line item and destroyed the mix, and the participants
     * are the only place the original numbers still exist.
     *
     * Grow-only. Shrinking here would repeat the very damage it repairs, and an
     * item that was legitimately shrunk (somebody really was removed) already
     * agrees with the participants, so it is left alone.
     *
     * @param \WC_Order $order
     * @param int[] $participant_ids
     * @return array{restored:bool,reason:string}
     */
    private static function restoreParty($order, int $product_id, array $participant_ids): array
    {
        $nothing = ['restored' => false, 'reason' => ''];

        $map = [];
        $seats = 0;
        foreach ($participant_ids as $participant_id) {
            $status = get_post_status($participant_id);
            if ($status === false || $status === 'trash' || $status === 'auto-draft') {
                continue;
            }
            $raw_types = get_post_meta($participant_id, 'selected_participant_types_data', true);
            $types = self::participantTypes($participant_id);
            if (!is_array($raw_types)) {
                $seats++; // no map on the post: it stands for one person
                continue;
            }
            foreach ($types as $type => $count) {
                $map[$type] = (isset($map[$type]) ? (int) $map[$type] : 0) + (int) $count;
                $seats += (int) $count;
            }
        }

        // No participants at all is not evidence of an empty party, only of a
        // roster that was never built, so the order is left exactly as it is.
        if ($seats < 1) {
            return $nothing;
        }

        $before = self::partySize($order, $product_id);
        if ($seats <= $before) {
            return $nothing;
        }

        // A map that does not account for every seat cannot be written without
        // inventing a participant type, so the order is reported instead.
        if (array_sum($map) !== $seats) {
            return [
                'restored' => false,
                'reason' => sprintf(
                    /* translators: 1: seats the participants add up to, 2: seats currently booked on the order */
                    __('The participants add up to %1$d seats but the order books %2$d, and their participant types do not cover the difference. Correct the line item by hand.', 'alttag-registrations'),
                    $seats,
                    $before
                ),
            ];
        }

        foreach ($order->get_items() as $item) {
            if ((int) $item->get_product_id() !== $product_id) {
                continue;
            }

            $key = 'selected_participant_types_data';
            if (!is_array($item->get_meta($key)) && is_array($item->get_meta('_' . $key))) {
                $key = '_' . $key;
            }

            // Quantity is deliberately untouched: on a participant-type item it
            // is 1 for the whole party (see shrinkItem()), and raising it would
            // restate the invoice line as N items at the party's price.
            $item->update_meta_data($key, $map);
            $item->save();

            $order->add_order_note(sprintf(
                'Grouped seating: party restored from participants, %d -> %d.',
                $before,
                $seats
            ));

            return ['restored' => true, 'reason' => ''];
        }

        return $nothing;
    }

    /**
     * The participant types one participant post stands for.
     *
     * Empty when the post carries no map, which under the multi-participant
     * flow is every post: MultiParticipantModule strips the order's aggregated
     * map from the buyer and from every extra, so there each post is one seat.
     *
     * @param int $participant_id
     * @return array<string,int>
     */
    public static function participantTypes($participant_id): array
    {
        $types = get_post_meta((int) $participant_id, 'selected_participant_types_data', true);
        if (!is_array($types)) {
            return [];
        }

        $map = [];
        foreach ($types as $type => $count) {
            $count = (int) $count;
            if ($count > 0) {
                $map[(string) $type] = $count;
            }
        }

        return $map;
    }

    /**
     * Number of seats represented by one participant post.
     *
     * A missing map means the traditional one-person participant. An explicit
     * empty map means an admin removed every seat and must therefore stay zero.
     */
    public static function participantSeatCount($participant_id): int
    {
        $raw = get_post_meta((int) $participant_id, 'selected_participant_types_data', true);

        return is_array($raw) ? array_sum(self::participantTypes($participant_id)) : 1;
    }

    /**
     * Replace the editable participant-type counts and synchronize both ledgers.
     *
     * Missing submitted keys keep their current value; an explicit zero removes
     * the type. This makes the method safe for both the admin form and WP-CLI.
     *
     * @return array{changed:bool,before:int,after:int,order_before:int,order_after:int,message:string,changes:array<string,array{from:int,to:int}>}
     */
    public static function updateParticipantSeatCounts(int $participant_id, array $counts, string $actor = ''): array
    {
        $result = [
            'changed' => false,
            'before' => 0,
            'after' => 0,
            'order_before' => 0,
            'order_after' => 0,
            'message' => '',
            'changes' => [],
        ];

        if (get_post_type($participant_id) !== 'participant') {
            return $result;
        }

        $raw = get_post_meta($participant_id, 'selected_participant_types_data', true);
        if (!is_array($raw)) {
            return $result;
        }

        $before = self::participantTypes($participant_id);
        $allowed = array_fill_keys(array_keys($before), true);
        $product_id = (int) get_post_meta($participant_id, 'product_id', true);
        foreach ((array) get_post_meta($product_id, '_participant_types', true) as $type) {
            $type_id = is_array($type) ? sanitize_key($type['id'] ?? '') : '';
            if ($type_id !== '') {
                $allowed[$type_id] = true;
            }
        }

        $submitted = [];
        foreach ($counts as $type_id => $count) {
            $type_id = sanitize_key((string) $type_id);
            if ($type_id !== '' && isset($allowed[$type_id])) {
                $submitted[$type_id] = min(99999, max(0, (int) $count));
            }
        }

        $after = [];
        foreach ($allowed as $type_id => $unused) {
            $count = array_key_exists($type_id, $submitted)
                ? $submitted[$type_id]
                : (int) ($before[$type_id] ?? 0);
            if ($count > 0) {
                $after[$type_id] = $count;
            }
        }

        $before_compare = $before;
        $after_compare = $after;
        ksort($before_compare);
        ksort($after_compare);
        $result['before'] = array_sum($before);
        $result['after'] = array_sum($after);
        if ($before_compare === $after_compare) {
            return $result;
        }

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $type_id) {
            $from = (int) ($before[$type_id] ?? 0);
            $to = (int) ($after[$type_id] ?? 0);
            if ($from !== $to) {
                $result['changes'][$type_id] = ['from' => $from, 'to' => $to];
            }
        }

        update_post_meta($participant_id, 'selected_participant_types_data', $after);
        if (class_exists(ParticipantState::class)) {
            ParticipantState::clearCache($participant_id);
        }

        $order_id = (int) get_post_meta($participant_id, 'order_id', true);
        $order = $order_id > 0 ? wc_get_order($order_id) : false;
        if ($order instanceof \WC_Order && self::isEnabled($product_id)) {
            $result['order_before'] = self::partySize($order, $product_id);
            $participants = self::orderParticipants($order_id);
            if (!self::syncTypedParty($order, $product_id, $participants)) {
                $target = self::liveParticipants($order_id);
                if ($target < $result['order_before']) {
                    self::recalcForOrder($order_id, 0, true);
                } elseif ($target > $result['order_before']) {
                    self::restoreParty($order, $product_id, $participants);
                }
            }
            $order = wc_get_order($order_id);
            $result['order_after'] = $order instanceof \WC_Order
                ? self::partySize($order, $product_id)
                : $result['order_before'];
        }

        if ($actor === '') {
            $user = wp_get_current_user();
            $actor = $user && $user->exists() ? ($user->user_email ?: $user->user_login) : '-';
        }
        $labels = [];
        foreach ((array) get_post_meta($product_id, '_participant_types', true) as $type) {
            if (is_array($type) && sanitize_key($type['id'] ?? '') !== '') {
                $labels[sanitize_key($type['id'])] = (string) ($type['label'] ?? $type['id']);
            }
        }
        $parts = [];
        foreach ($result['changes'] as $type_id => $change) {
            $parts[] = sprintf(
                '%s: %d → %d',
                $labels[$type_id] ?? $type_id,
                $change['from'],
                $change['to']
            );
        }
        $result['message'] = sprintf(
            /* translators: 1: administrator identity, 2: changed seat counts */
            __('Participant seat counts changed by %1$s: %2$s.', 'alttag-registrations'),
            $actor,
            implode(', ', $parts)
        );

        $state = class_exists(ParticipantState::class) ? ParticipantState::get($participant_id) : null;
        if ($state) {
            $state->addToHistory($result['message']);
        }
        if ($order instanceof \WC_Order) {
            $order->add_order_note($result['message']);
            $order->save();
        }

        $result['changed'] = true;
        return $result;
    }

    /**
     * Copy the exact participant-type mix to the matching order item.
     *
     * Only fully typed rosters are eligible. A traditional participant without
     * a map makes the mix unknowable, so the older head-count reconciliation is
     * used instead.
     */
    private static function syncTypedParty($order, int $product_id, array $participant_ids): bool
    {
        $map = [];
        $found = false;
        foreach ($participant_ids as $participant_id) {
            $status = get_post_status($participant_id);
            if ($status === false || $status === 'trash' || $status === 'auto-draft') {
                continue;
            }
            if ((int) get_post_meta($participant_id, 'product_id', true) !== $product_id) {
                continue;
            }

            $raw = get_post_meta($participant_id, 'selected_participant_types_data', true);
            if (!is_array($raw)) {
                return false;
            }
            $found = true;
            foreach (self::participantTypes($participant_id) as $type_id => $count) {
                $map[$type_id] = (int) ($map[$type_id] ?? 0) + $count;
            }
        }
        if (!$found) {
            return false;
        }

        foreach ($order->get_items() as $item) {
            if ((int) $item->get_product_id() !== $product_id) {
                continue;
            }
            $key = 'selected_participant_types_data';
            if (!is_array($item->get_meta($key)) && is_array($item->get_meta('_' . $key))) {
                $key = '_' . $key;
            }
            $item->update_meta_data($key, $map);
            $item->save();
            $order->save();
            return true;
        }

        return false;
    }

    /**
     * Find the wave an order really booked when its stored slot time is gone.
     *
     * The participants keep the wave's LABEL as well as its time, and a slot is
     * usually relabelled or re-keyed rather than removed, so those two values are
     * the only evidence left of the original choice.
     *
     * @param int[] $participant_ids
     */
    private static function resolveWave($product_id, string $current, array $participant_ids): string
    {
        $waves = self::waves($product_id);

        $candidates = [$current];
        foreach ($participant_ids as $participant_id) {
            $candidates[] = (string) get_post_meta($participant_id, self::META_WAVE, true);
            $candidates[] = (string) get_post_meta($participant_id, self::META_TIME, true);
        }

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if (isset($waves[$candidate])) {
                return $candidate;
            }
            foreach ($waves as $time => $wave) {
                if ((string) $wave['label'] === $candidate) {
                    return (string) $time;
                }
            }
        }

        return '';
    }

    /**
     * Every participant belonging to an order.
     *
     * @param int $order_id
     * @return int[]
     */
    public static function orderParticipants($order_id): array
    {
        $ids = get_posts([
            'post_type' => 'participant',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_query' => [
                ['key' => 'order_id', 'value' => (int) $order_id],
            ],
        ]);

        return array_map('intval', (array) $ids);
    }

    // ------------------------------------------------- releasing a seat again

    /**
     * A participant was trashed or deleted: recount the order's party.
     *
     * `before_delete_post` fires while the row still exists, so the post being
     * removed is passed on and excluded from the count explicitly instead of
     * relying on its status.
     */
    public function participantRemoved($post_id)
    {
        $post_id = (int) $post_id;

        if (get_post_type($post_id) !== 'participant') {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $order_id = (int) get_post_meta($post_id, 'order_id', true);
        if ($order_id > 0) {
            self::recalcForOrder($order_id, $post_id);
        }
    }

    /**
     * Bring an order's booked party size back in line with its participants.
     *
     * The people who actually attend are the participant posts, but the seats
     * are counted from the order, so the two drift apart the moment an admin
     * removes somebody. Counting participants (not order items) is what makes a
     * deletion free a seat, and the new number is written back onto the line
     * item so `partySize()` - and therefore the ledger, the checkout gate and
     * every station overview - sees it without a second source of truth.
     *
     * Shrink-only on purpose: growing a party again could exceed the station's
     * capacity, which is a placement decision an admin has to make.
     *
     * @param int $order_id
     * @param int $removed_participant_id participant being removed right now
     * @param bool $include_released synchronize a manually edited refunded/cancelled order too
     * @return int the party size after the recount, 0 when nothing applies
     */
    public static function recalcForOrder(int $order_id, int $removed_participant_id = 0, bool $include_released = false): int
    {
        if ($removed_participant_id > 0 && class_exists(ParticipantState::class)) {
            ParticipantState::clearCache($removed_participant_id);
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return 0;
        }

        // Automated deletion does not touch released orders. A manual edit still
        // synchronizes their line item so restoring the order cannot revive seats.
        if (!$include_released && in_array($order->get_status(), self::RELEASED_STATUSES, true)) {
            return 0;
        }

        $product_id = 0;
        foreach ($order->get_items() as $item) {
            $candidate = (int) $item->get_product_id();
            if (self::isEnabled($candidate)) {
                $product_id = $candidate;
                break;
            }
        }
        if (!$product_id) {
            return 0;
        }

        if ($removed_participant_id > 0) {
            self::forgetExtraParticipant($order_id, $removed_participant_id);
        }

        $before = self::partySize($order, $product_id);

        // Extras that were never turned into participant posts would otherwise
        // read as people who left. The roster still promises them a seat, so
        // the order keeps it until those posts exist.
        $extras = get_post_meta($order_id, '_alttag_extra_participants', true);
        if (is_array($extras) && $extras
            && (count($extras) + 1) > self::livePosts($order_id, $removed_participant_id)) {
            return $before;
        }

        // Nobody left to count is not the same as a party of nobody: with no
        // participant posts there is no evidence to shrink an order on.
        $target = self::liveParticipants($order_id, $removed_participant_id);
        if (($target < 1 && self::livePosts($order_id, $removed_participant_id) < 1) || $target >= $before) {
            return $before;
        }

        $to_release = $before - $target;

        foreach ($order->get_items() as $item) {
            if ($to_release < 1 || (int) $item->get_product_id() !== $product_id) {
                continue;
            }
            $to_release -= self::shrinkItem($item, $to_release);
        }

        $after = self::partySize($order, $product_id);
        if ($after === $before) {
            return $before;
        }

        $order->add_order_note(sprintf(
            'Grouped seating: seat released, party %d -> %d.',
            $before,
            $after
        ));
        $order->save();

        do_action('alttag_registrations_group_party_recalculated', $order, $after, $before, $product_id);

        return $after;
    }

    /**
     * Seats held by the participants of an order that still exist.
     *
     * Seats, not posts: one participant post can carry a whole family in its
     * `selected_participant_types_data` map, so counting posts would read a
     * party of five as a party of one and release four booked seats.
     *
     * @param int $order_id
     * @param int $exclude_participant_id
     */
    public static function liveParticipants($order_id, $exclude_participant_id = 0): int
    {
        $seats = 0;

        foreach (self::orderParticipants($order_id) as $participant_id) {
            if ($participant_id === (int) $exclude_participant_id) {
                continue;
            }
            $status = get_post_status($participant_id);
            if ($status === false || $status === 'trash' || $status === 'auto-draft') {
                continue;
            }
            $seats += self::participantSeatCount($participant_id);
        }

        return $seats;
    }

    /**
     * Participant POSTS of an order that still exist.
     *
     * Separate from liveParticipants() because the extras check asks how many
     * people were materialised, not how many seats they hold.
     */
    private static function livePosts(int $order_id, int $exclude_participant_id = 0): int
    {
        $live = 0;

        foreach (self::orderParticipants($order_id) as $participant_id) {
            if ($participant_id === $exclude_participant_id) {
                continue;
            }
            $status = get_post_status($participant_id);
            if ($status === false || $status === 'trash' || $status === 'auto-draft') {
                continue;
            }
            $live++;
        }

        return $live;
    }

    /**
     * Take seats off one line item.
     *
     * An explicit empty participant-type map is zero seats; an item without such
     * a map retains the legacy minimum quantity of one.
     *
     * @param \WC_Order_Item_Product $item
     * @return int seats actually released
     */
    private static function shrinkItem($item, int $take): int
    {
        $key = 'selected_participant_types_data';
        $types = $item->get_meta($key);
        if (!is_array($types)) {
            $key = '_selected_participant_types_data';
            $types = $item->get_meta($key);
        }

        $quantity = max(1, (int) $item->get_quantity());

        if (is_array($types)) {
            $types = array_map('intval', $types);
            $released = self::shrinkTypeCounts($types, $take);
            if ($released < 1) {
                return 0;
            }
            $item->update_meta_data($key, $types);
        } else {
            $released = max(0, min($take, $quantity - 1));
            if ($released < 1) {
                return 0;
            }
        }

        // Quantity is only lowered when it really carries the head count; on a
        // participant-type item it is 1 for the whole party and must stay 1.
        $item->set_quantity(max(1, $quantity - $released));
        $item->save();

        return $released;
    }

    /**
     * Remove $take seats from a type map, always off the largest type.
     *
     * Participants carry no type of their own, so which type the removed person
     * held is unknowable. Emptying the largest bucket first keeps the remaining
     * mix closest to the booked one and, unlike proportional scaling, can never
     * produce a fraction or a negative count. Types that reach zero are dropped
     * so no roster shows an "adult: 0" row.
     *
     * @param array<string,int> $types modified in place
     * @return int seats actually removed
     */
    public static function shrinkTypeCounts(array &$types, int $take): int
    {
        $released = 0;

        while ($released < $take && array_sum($types) > 0) {
            $largest = null;
            foreach ($types as $type => $count) {
                if ((int) $count < 1) {
                    continue;
                }
                if ($largest === null || (int) $count > (int) $types[$largest]) {
                    $largest = $type;
                }
            }
            if ($largest === null) {
                break;
            }
            $types[$largest] = (int) $types[$largest] - 1;
            $released++;
        }

        foreach ($types as $type => $count) {
            if ((int) $count < 1) {
                unset($types[$type]);
            }
        }

        return $released;
    }

    /**
     * Drop a removed person from the order's extra-attendee list.
     *
     * That list feeds the invoice comment and the buyer's roster, so leaving a
     * deleted name in it would keep showing somebody who is no longer coming.
     * Matched by e-mail because it is the only field both records share.
     */
    private static function forgetExtraParticipant(int $order_id, int $participant_id): void
    {
        $extras = get_post_meta($order_id, '_alttag_extra_participants', true);
        if (!is_array($extras) || !$extras) {
            return;
        }

        $email = strtolower(trim((string) get_post_meta($participant_id, 'email', true)));
        if ($email === '') {
            return;
        }

        $kept = [];
        $dropped = false;
        foreach ($extras as $attendee) {
            if (!$dropped && strtolower(trim((string) ($attendee['email'] ?? ''))) === $email) {
                $dropped = true;
                continue;
            }
            $kept[] = $attendee;
        }
        if (!$dropped) {
            return;
        }

        if ($kept) {
            update_post_meta($order_id, '_alttag_extra_participants', $kept);
        } else {
            delete_post_meta($order_id, '_alttag_extra_participants');
        }
    }

    /** Copy the order's station onto the participant. */
    public function inheritOnParticipant($participant_id, $order_data = [])
    {
        $participant_id = (int) $participant_id;
        if (!$participant_id) {
            return;
        }

        $product_id = (int) get_post_meta($participant_id, 'product_id', true);
        if (!self::isEnabled($product_id)) {
            return;
        }

        if ((string) get_post_meta($participant_id, self::META_GROUP, true) !== '') {
            return;
        }

        $order_id = (int) ($order_data['order_id'] ?? get_post_meta($participant_id, 'order_id', true));
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }

        $group = (string) $order->get_meta(self::ORDER_GROUP);
        if ($group === '') {
            return;
        }

        self::writeParticipantGroup(
            $participant_id,
            $product_id,
            $group,
            (string) $order->get_meta(self::ORDER_WAVE)
        );
    }

    /** Single place that writes station meta on a participant (admin edits too). */
    public static function writeParticipantGroup($participant_id, $product_id, string $group, string $wave = ''): void
    {
        update_post_meta($participant_id, self::META_GROUP, $group);

        if ($wave !== '') {
            update_post_meta($participant_id, self::META_WAVE, $wave);
            update_post_meta($participant_id, self::META_TIME, self::waveLabel($product_id, $wave));
            // The ticket header, emails and thank-you page read the booking slot
            // (selected_session_slot), not the seating meta. Keep it in sync so
            // an admin wave move regenerates a consistent ticket.
            update_post_meta($participant_id, 'selected_session_slot', $wave);
        } else {
            delete_post_meta($participant_id, self::META_WAVE);
            delete_post_meta($participant_id, self::META_TIME);
        }
    }

    public static function waveLabel($product_id, string $wave): string
    {
        $waves = self::waves($product_id);

        return isset($waves[$wave]) ? (string) $waves[$wave]['label'] : $wave;
    }

    // --------------------------------------------------------------- display

    /** "A, Podvečerná - VLNA 1 (16:00 - 18:30 hod.)" or an empty string. */
    public static function label($participant_id): string
    {
        $group = (string) get_post_meta((int) $participant_id, self::META_GROUP, true);
        if ($group === '') {
            return '';
        }

        $time = (string) get_post_meta((int) $participant_id, self::META_TIME, true);

        return $time !== '' ? $group . ', ' . $time : $group;
    }

    // -------------------------------------------------------- product fields

    public function productFields()
    {
        global $post;
        if (!$post) {
            return;
        }

        echo '<div class="options_group">';

        woocommerce_wp_checkbox([
            'id' => self::PRODUCT_ENABLED,
            'value' => get_post_meta($post->ID, self::PRODUCT_ENABLED, true),
            'cbvalue' => 'yes',
            'label' => __('Grouped seating', 'alttag-registrations'),
            'description' => __('Assign each booking to a station inside the time slot it chose.', 'alttag-registrations'),
        ]);

        woocommerce_wp_text_input([
            'id' => self::PRODUCT_GROUPS,
            'value' => get_post_meta($post->ID, self::PRODUCT_GROUPS, true),
            'label' => __('Stations per time slot', 'alttag-registrations'),
            'placeholder' => (string) self::DEFAULT_GROUPS,
            'type' => 'number',
            'custom_attributes' => ['min' => '1', 'step' => '1'],
            'description' => __('Seats per station = the time slot capacity divided by this number.', 'alttag-registrations'),
            'desc_tip' => true,
        ]);

        echo '</div>';
    }

    public function saveProductFields($post_id)
    {
        $post_id = (int) $post_id;

        update_post_meta(
            $post_id,
            self::PRODUCT_ENABLED,
            isset($_POST[self::PRODUCT_ENABLED]) ? 'yes' : 'no'
        );

        $n = isset($_POST[self::PRODUCT_GROUPS]) ? absint($_POST[self::PRODUCT_GROUPS]) : 0;
        if ($n > 0) {
            update_post_meta($post_id, self::PRODUCT_GROUPS, $n);
        } else {
            delete_post_meta($post_id, self::PRODUCT_GROUPS);
        }
    }
}
