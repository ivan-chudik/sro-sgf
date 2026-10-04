<?php

namespace Alttag\Registrations;

use Alttag\Registrations\Selection\SelectionManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-product configuration state.
 * Cached, lazy-loaded. Single source of truth for product capabilities,
 * event settings, pricing, and selection types.
 *
 * Usage:
 *   $pc = ProductConfig::get($product_id);
 *   $pc->eventName();
 *   $pc->isLivestream();
 *   $pc->availableDates();
 *   $pc->generatesTicket();
 *   $pc->maxParticipants();
 */
class ProductConfig
{
    /** @var array<int, self> */
    private static $cache = [];

    /** @var int */
    public $id;

    /** @var \WC_Product|null|false */
    private $product = false;

    /** @var array|null */
    private $available_dates = null;

    /** @var array|null */
    private $event_details = null;

    private function __construct(int $product_id)
    {
        $this->id = $product_id;
    }

    /**
     * Get ProductConfig (cached)
     */
    public static function get($product_id)
    {
        $product_id = (int) $product_id;
        if (!$product_id) {
            return null;
        }

        if (!isset(self::$cache[$product_id])) {
            self::$cache[$product_id] = new self($product_id);
        }
        return self::$cache[$product_id];
    }

    /**
     * Get from current context (cart/order/participant)
     */
    public static function current()
    {
        $product_id = EventManager::getCurrentContextProductId();
        return $product_id ? self::get($product_id) : null;
    }

    public static function clearCache($product_id = null)
    {
        if ($product_id) {
            unset(self::$cache[(int) $product_id]);
        } else {
            self::$cache = [];
        }
    }

    // =========================================================================
    // Product object
    // =========================================================================

    public function product()
    {
        if ($this->product === false) {
            $this->product = wc_get_product($this->id);
        }
        return $this->product;
    }

    public function name()
    {
        $product = $this->product();
        return $product ? $product->get_name() : '';
    }

    public function sku()
    {
        $product = $this->product();
        return $product ? $product->get_sku() : '';
    }

    public function price()
    {
        $product = $this->product();
        return $product ? (float) $product->get_price() : 0;
    }

    // =========================================================================
    // Product type / capabilities
    // =========================================================================

    /**
     * Is this a livestream/online product?
     */
    public function isLivestream()
    {
        return product_is_livestream($this->id);
    }

    /**
     * Is this an in-person product?
     */
    public function isInperson()
    {
        return !$this->isLivestream();
    }

    /**
     * Does this product generate a PDF ticket?
     */
    public function generatesTicket()
    {
        return product_generates_ticket($this->id);
    }

    /**
     * Should QR code be shown for this product?
     */
    public function showsQrCode()
    {
        $meta = get_post_meta($this->id, '_show_qr_code', true);
        if ($meta === '0' || $meta === 0) {
            return false;
        }
        // Online products never show QR
        if ($this->isLivestream()) {
            return false;
        }
        return true;
    }

    /**
     * Max participants per order for this product
     */
    public function maxParticipants()
    {
        return (int) get_post_meta($this->id, '_max_participants', true);
    }

    /**
     * Does this product support multi-person registration?
     */
    public function isMultiPerson()
    {
        return $this->maxParticipants() > 1;
    }

    // =========================================================================
    // Event configuration
    // =========================================================================

    /**
     * Get event name from product meta
     */
    public function eventName()
    {
        return Settings::getValue('general.event_name', $this->id);
    }

    /**
     * Get event start date
     */
    public function eventDateStart()
    {
        return Settings::getValue('general.event_date_start', $this->id);
    }

    /**
     * Get event end date
     */
    public function eventDateEnd()
    {
        return Settings::getValue('general.event_date_end', $this->id);
    }

    /**
     * Get event dates as array ['start' => ..., 'end' => ...]
     */
    public function eventDates()
    {
        $dates = [];
        $start = $this->eventDateStart();
        $end = $this->eventDateEnd();
        if ($start) {
            $dates['start'] = $start;
        }
        if ($end) {
            $dates['end'] = $end;
        }

        // A product configured through Session dates knows when it happens even
        // without the explicit start and end fields, and without this the date
        // came out empty in sentences like "takes place on <date>".
        if (empty($dates['start'])) {
            $range = $this->sessionDateRange();
            if ($range) {
                $dates = $range;
            }
        }

        return $dates;
    }

    /**
     * First and last date from the product's session dates.
     *
     * @return array{start:string,end:string}|null
     */
    private function sessionDateRange(): ?array
    {
        $sessions = get_post_meta($this->id, '_session_dates', true);
        if (!is_array($sessions) || !$sessions) {
            return null;
        }

        $days = [];
        foreach ($sessions as $session) {
            $date = is_array($session) ? (string) ($session['date'] ?? '') : '';
            if ($date !== '') {
                $days[] = $date;
            }
        }
        if (!$days) {
            return null;
        }

        sort($days);

        return ['start' => reset($days), 'end' => end($days)];
    }

    /**
     * Get formatted event dates string
     */
    public function eventDatesString()
    {
        $dates = $this->eventDates();
        if (empty($dates['start'])) {
            return '';
        }

        if ($dates['start'] === ($dates['end'] ?? '')) {
            return date_i18n('j. n. Y', strtotime($dates['start']));
        }

        $start_ts = strtotime($dates['start']);
        $end_ts = strtotime($dates['end']);
        $same_month = date('n', $start_ts) === date('n', $end_ts)
            && date('Y', $start_ts) === date('Y', $end_ts);
        $same_year = date('Y', $start_ts) === date('Y', $end_ts);

        if ($same_month) {
            // 8 - 19. 8. 2026 — drop month/year on start
            return date_i18n('j', $start_ts) . ' - ' . date_i18n('j. n. Y', $end_ts);
        }

        if ($same_year) {
            // 8. 7. - 19. 8. 2026 — keep month on start, drop year only
            return date_i18n('j. n.', $start_ts) . ' - ' . date_i18n('j. n. Y', $end_ts);
        }

        // 28. 12. 2025 - 5. 1. 2026 — full date on both sides
        return date_i18n('j. n. Y', $start_ts) . ' - ' . date_i18n('j. n. Y', $end_ts);
    }

    /**
     * Get event name with year. Skips appending the year if it's already in the name.
     */
    public function eventNameWithYear()
    {
        $name = $this->eventName();
        $dates = $this->eventDates();
        $year = !empty($dates['start']) ? date('Y', strtotime($dates['start'])) : date('Y');
        if ($name === '' || strpos($name, (string) $year) !== false) {
            return $name;
        }
        return $name . ' ' . $year;
    }

    // =========================================================================
    // Event details (dynamic key-value rows)
    // =========================================================================

    /**
     * Get event detail rows from product meta
     * @return array [['label' => ..., 'value' => ...], ...]
     */
    public function eventDetails()
    {
        if ($this->event_details === null) {
            $this->event_details = EventManager::getProductEventDetails($this->id);
        }
        return $this->event_details;
    }

    /**
     * Find an event detail value by label keyword
     */
    public function eventDetail($keyword)
    {
        foreach ($this->eventDetails() as $row) {
            if (stripos($row['label'], $keyword) !== false) {
                return $row['value'];
            }
        }
        return '';
    }

    /**
     * Get venue (from event details)
     */
    public function venue()
    {
        return Settings::getValue('general.venue', $this->id);
    }

    /**
     * Get address
     */
    public function address()
    {
        return Settings::getValue('general.venue_address', $this->id);
    }

    /**
     * Get full location (venue + address)
     */
    public function fullLocation()
    {
        $venue = $this->venue();
        $address = $this->address();

        if ($venue && $address) {
            return $venue . ', ' . $address;
        }
        return $venue ?: $address;
    }

    // =========================================================================
    // Multi-day / Selection
    // =========================================================================

    /**
     * Is this a multi-day event product?
     */
    public function isMultiDay()
    {
        return !empty($this->availableDates());
    }

    /**
     * Get available dates ['date' => 'label', ...]
     */
    public function availableDates()
    {
        if ($this->available_dates === null) {
            $day_type = SelectionManager::getType('days');
            $this->available_dates = $day_type
                ? $day_type->getAvailableOptions($this->id)
                : [];
        }
        return $this->available_dates;
    }

    /**
     * Get per-day prices ['date' => price, ...]
     */
    public function dayPrices()
    {
        $day_type = SelectionManager::getType('days');
        return $day_type ? $day_type->getOptionPrices($this->id) : [];
    }

    /**
     * Get pricing tiers [count => price, ...]
     */
    public function pricingTiers()
    {
        $day_type = SelectionManager::getType('days');
        return $day_type ? $day_type->getPricingTiers($this->id) : [];
    }

    /**
     * Has day-based pricing?
     */
    public function hasDayPricing()
    {
        return !empty($this->dayPrices());
    }

    /**
     * Get active selection types for this product
     * @return \Alttag\Registrations\Selection\AbstractSelection[]
     */
    public function activeSelections()
    {
        return SelectionManager::getActiveForProduct($this->id);
    }

    /**
     * Has any active selection type?
     */
    public function hasSelections()
    {
        return !empty($this->activeSelections());
    }
}
