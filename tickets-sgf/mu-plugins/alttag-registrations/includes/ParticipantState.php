<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralized participant state object.
 * Loads all meta in one query, provides typed access,
 * lazy-loads related objects (order, product).
 *
 * Usage:
 *   $state = ParticipantState::get($participant_id);
 *   $state->email;
 *   $state->product();
 *   $state->order();
 *   $state->isLivestream();
 */
class ParticipantState
{
    /** @var array<int, self> Instance cache */
    private static $cache = [];

    /** @var int */
    public $id;

    /** @var array Raw meta data */
    private $meta = [];

    /** @var \WC_Product|null|false Lazy-loaded product (false = not loaded yet) */
    private $product = false;

    /** @var \WC_Order|null|false Lazy-loaded order (false = not loaded yet) */
    private $order = false;

    /** @var \WP_Post|null The participant post */
    private $post;

    // =========================================================================
    // Construction & caching
    // =========================================================================

    private function __construct(int $participant_id)
    {
        $this->id = $participant_id;
        $this->post = get_post($participant_id);
        $this->loadMeta();
    }

    /**
     * Get a ParticipantState instance (cached per request)
     *
     * @param int $participant_id
     * @return self|null
     */
    public static function get($participant_id)
    {
        $participant_id = (int) $participant_id;
        if (!$participant_id) {
            return null;
        }

        if (!isset(self::$cache[$participant_id])) {
            $post = get_post($participant_id);
            if (!$post || $post->post_type !== 'participant') {
                return null;
            }
            self::$cache[$participant_id] = new self($participant_id);
        }

        return self::$cache[$participant_id];
    }

    /**
     * Create a ParticipantState from order data (before meta is in DB)
     *
     * @param int $participant_id
     * @param array $order_data
     * @return self
     */
    public static function fromOrderData($participant_id, array $order_data)
    {
        $state = new self($participant_id);

        // Overlay order_data on top of DB meta
        foreach ($order_data as $key => $value) {
            $state->meta[$key] = $value;
        }

        self::$cache[$participant_id] = $state;
        return $state;
    }

    /**
     * Clear cache for a specific participant or all
     */
    public static function clearCache($participant_id = null)
    {
        if ($participant_id) {
            unset(self::$cache[(int) $participant_id]);
        } else {
            self::$cache = [];
        }
    }

    /**
     * Reload meta from DB
     */
    public function reload()
    {
        $this->loadMeta();
        $this->product = false;
        $this->order = false;
    }

    /**
     * Load all meta in one query
     */
    private function loadMeta()
    {
        $all_meta = get_post_meta($this->id);
        $this->meta = [];

        foreach ($all_meta as $key => $values) {
            $value = $values[0] ?? '';
            // Unserialize if needed
            $unserialized = maybe_unserialize($value);
            $this->meta[$key] = $unserialized;
        }
    }

    // =========================================================================
    // Generic meta access
    // =========================================================================

    /**
     * Get a raw meta value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getMeta($key, $default = '')
    {
        return $this->meta[$key] ?? $default;
    }

    /**
     * Set a meta value (in memory and DB)
     *
     * @param string $key
     * @param mixed $value
     */
    public function setMeta($key, $value)
    {
        $this->meta[$key] = $value;
        update_post_meta($this->id, $key, $value);
    }

    /**
     * Magic getter for direct property access: $state->email
     */
    public function __get($name)
    {
        return $this->getMeta($name);
    }

    /**
     * Magic isset for property checks: isset($state->email)
     */
    public function __isset($name)
    {
        return isset($this->meta[$name]) && $this->meta[$name] !== '';
    }

    // =========================================================================
    // Identity
    // =========================================================================

    public function getFullName()
    {
        $parts = [];

        $titles_before = $this->getMeta('titles_before_name');
        if (!empty($titles_before)) {
            $parts[] = $titles_before;
        }

        $parts[] = $this->getMeta('first_name');
        $parts[] = $this->getMeta('last_name');

        $titles_after = $this->getMeta('titles_after_name');
        if (!empty($titles_after)) {
            $parts[count($parts) - 1] .= ',';
            $parts[] = $titles_after;
        }

        return implode(' ', array_filter($parts));
    }

    public function getDisplayName()
    {
        return trim($this->getMeta('first_name') . ' ' . $this->getMeta('last_name'));
    }

    // =========================================================================
    // Related objects (lazy-loaded)
    // =========================================================================

    /**
     * Get the WooCommerce order
     *
     * @return \WC_Order|null
     */
    public function order()
    {
        if ($this->order === false) {
            $order_id = $this->getMeta('order_id');
            $this->order = $order_id ? wc_get_order($order_id) : null;
        }
        return $this->order;
    }

    /**
     * Get the WooCommerce product
     *
     * @return \WC_Product|null
     */
    public function product()
    {
        if ($this->product === false) {
            $product_id = $this->getMeta('product_id');
            $this->product = $product_id ? wc_get_product($product_id) : null;
        }
        return $this->product;
    }

    /**
     * Get product SKU
     *
     * @return string
     */
    public function productSku()
    {
        $product = $this->product();
        return $product ? $product->get_sku() : '';
    }

    /**
     * Get product name (from meta or product object)
     *
     * @return string
     */
    public function productName()
    {
        $name = $this->getMeta('product_name');
        if (!empty($name)) {
            return $name;
        }

        $product = $this->product();
        return $product ? $product->get_name() : '';
    }

    // =========================================================================
    // Registration status
    // =========================================================================

    /**
     * Get registration status
     *
     * @return string One of: pending, confirmed, cancelled, completed, partial
     */
    public function registrationStatus()
    {
        return $this->getMeta('registration_status', 'pending');
    }

    /**
     * Is this participant confirmed?
     */
    public function isConfirmed()
    {
        return in_array($this->registrationStatus(), ['confirmed', 'completed']);
    }

    /**
     * Is this participant cancelled?
     */
    public function isCancelled()
    {
        return $this->registrationStatus() === 'cancelled';
    }

    // =========================================================================
    // Livestream
    // =========================================================================

    /**
     * Is this a livestream participant?
     */
    public function isLivestream()
    {
        // Explicit per-participant flag wins. The conference-selector model
        // tracks livestream attendance per-day (a single product can mix
        // online and in-person days), so `is_livestream_user` is the
        // authoritative signal whenever it's set. Falling through to the
        // product-level check first — as the legacy code did — caused
        // mixed-mode attendees on a "neutral" product to incorrectly
        // resolve as non-livestream.
        $meta = $this->getMeta('is_livestream_user');
        if (in_array($meta, ['1', 1, true, 'yes', 'true'], true)) {
            return true;
        }

        // Product-level fallback for legacy projects with per-mode products
        // (each product was either livestream or in-person, no per-day mix).
        $product_id = $this->getMeta('product_id');
        if ($product_id) {
            return product_is_livestream($product_id);
        }

        return (bool) $meta;
    }

    /**
     * Is this an in-person participant?
     */
    public function isInperson()
    {
        return !$this->isLivestream();
    }

    /**
     * Get livestream access status
     *
     * @return string One of: not_granted, pending, granted, failed
     */
    public function livestreamAccessStatus()
    {
        return $this->getMeta('livestream_access', 'not_granted');
    }

    /**
     * Has livestream access been granted?
     */
    public function hasLivestreamAccess()
    {
        return $this->livestreamAccessStatus() === 'granted';
    }

    // =========================================================================
    // Multi-day / Event
    // =========================================================================

    /**
     * Get selected days
     *
     * @return array
     */
    public function selectedDays()
    {
        $days = $this->getMeta('selected_days', []);
        return is_array($days) ? $days : [];
    }

    /**
     * Get selected days data (date => person count)
     *
     * @return array
     */
    public function selectedDaysData()
    {
        $data = $this->getMeta('selected_days_data', []);
        return is_array($data) ? $data : [];
    }

    /**
     * Get registered dates
     *
     * @return array
     */
    public function registeredDates()
    {
        $dates = $this->getMeta('registered_dates', []);
        return is_array($dates) ? $dates : [];
    }

    /**
     * Get verified days data (date => verified count)
     *
     * @return array
     */
    public function verifiedDaysData()
    {
        $data = $this->getMeta('verified_days_data', []);
        return is_array($data) ? $data : [];
    }

    /**
     * Is a specific date registered?
     */
    public function isDateRegistered($date)
    {
        return in_array($date, $this->registeredDates());
    }

    /**
     * Get verified count for a specific date
     */
    public function verifiedCountForDate($date)
    {
        $data = $this->verifiedDaysData();
        return (int) ($data[$date] ?? 0);
    }

    /**
     * Get total person count for a specific date
     */
    public function personCountForDate($date)
    {
        $data = $this->selectedDaysData();
        return (int) ($data[$date] ?? 1);
    }

    // =========================================================================
    // Documents
    // =========================================================================

    /**
     * Should this participant get a PDF ticket?
     */
    public function shouldGetTicket()
    {
        $product_id = $this->getMeta('product_id');
        if ($product_id) {
            $pc = ProductConfig::get($product_id);
            return $pc ? $pc->generatesTicket() : true;
        }
        return true;
    }

    /**
     * Should QR code be shown?
     */
    public function shouldShowQrCode()
    {
        $product_id = $this->getMeta('product_id');
        if ($product_id) {
            $pc = ProductConfig::get($product_id);
            return $pc ? $pc->showsQrCode() : true;
        }
        return !$this->isLivestream();
    }

    /**
     * Has ticket been generated?
     */
    public function hasTicket()
    {
        return !empty($this->getMeta('ticket_url'));
    }

    /**
     * Has invoice?
     */
    public function hasInvoice()
    {
        return !empty($this->getMeta('invoice_id'));
    }

    // =========================================================================
    // Language
    // =========================================================================

    /**
     * Get participant language
     */
    public function language()
    {
        return $this->getMeta('language') ?: get_default_language();
    }

    // =========================================================================
    // History
    // =========================================================================

    /**
     * Append to registration history
     *
     * @param string $entry
     */
    public function addToHistory($entry)
    {
        $timestamp = current_time('mysql');
        $full_entry = "[{$timestamp}] {$entry}";

        $history = $this->getMeta('registration_history', '');
        $updated = $history ? $history . "\n" . $full_entry : $full_entry;
        $this->setMeta('registration_history', $updated);
    }

    // =========================================================================
    // Serialization
    // =========================================================================

    /**
     * Get all meta as array (for passing to filters/actions)
     *
     * @return array
     */
    public function toArray()
    {
        return array_merge(['id' => $this->id], $this->meta);
    }

    /**
     * Get field data for FieldBuilder (ticket, verification, etc.)
     *
     * @return array
     */
    public function getFieldData()
    {
        $data = [];
        foreach (FieldBuilder::getFieldKeys() as $key) {
            $data[$key] = $this->getMeta($key, '');
        }
        return $data;
    }
}
