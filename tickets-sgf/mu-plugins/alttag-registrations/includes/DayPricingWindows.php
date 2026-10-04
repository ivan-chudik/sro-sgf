<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Early-bird / normal price windows for a day-priced product.
 *
 * A day-priced product gets its cart price from the days the visitor picks
 * (`_event_available_dates` prices + `_pricing_tiers`), which is exactly why
 * PricingTiers::prices() leaves it alone - a flat tier price would wipe the
 * computed sum out. So the two mechanisms have never been combinable: a
 * product could have day pricing or date windows, not both.
 *
 * This closes that gap from the other side. Instead of overriding the final
 * price it swaps the day-price map itself, at the single point where
 * DaySelection resolves it. Everything downstream follows for free: the cart
 * total, the per-day prices printed in the wrapper, the "instead of" summary
 * rows and the tiers handed to selection-days.js. Nothing hooks
 * woocommerce_before_calculate_totals, so the priority-20 ordering between
 * PricingTiers::prices() and SelectionManager::adjustPricing() is untouched.
 *
 * Applies only to a product that carries BOTH metas - `_pricing_tiers` (day
 * counts) and `_alttag_pricing_tiers` (date windows) - and whose currently
 * active window key is listed in WINDOW_DAY_PRICES. A product with day prices
 * but no date windows (SGO 5681) never matches and keeps its stored tiers.
 */
class DayPricingWindows
{
    /**
     * The only place the windowed day prices are defined.
     *
     * Keyed by the `key` of the `_alttag_pricing_tiers` row that is active on
     * the day of the request, then by number of selected days.
     *
     * The 3-day prices are confirmed by the client (2026-09-24) as the sum of
     * the neighbouring passes: 12.75 + 6.80 = 19.55 early, 15.00 + 8.00 =
     * 23.00 normal. Note this makes 3 days more expensive than the 4-day
     * pass (17.00 / 20.00) - deliberate, per the client's formula.
     */
    public const WINDOW_DAY_PRICES = [
        // Early bird, until 2026-10-15 23:59:59 Europe/Bratislava.
        'early-bird' => [
            1 => 6.80,
            2 => 12.75,
            3 => 19.55,
            4 => 17.00,
        ],
        // Normal price, from 2026-10-16.
        'normalna-cena' => [
            1 => 8.00,
            2 => 15.00,
            3 => 23.00,
            4 => 20.00,
        ],
    ];

    public function registerHooks()
    {
        add_filter('alttag_registrations_day_option_prices', [$this, 'dayPrices'], 10, 2);
        add_filter('alttag_registrations_day_pricing_tiers', [$this, 'dayTiers'], 10, 2);
    }

    /**
     * Per-day price of the active window.
     *
     * One day is the per-day price: it is the fallback when no tier matches and
     * the source of the struck-through "instead of" price in the summary.
     *
     * @param array $prices
     * @param int   $product_id
     * @return array
     */
    public function dayPrices($prices, $product_id)
    {
        $map = self::activeMap($product_id);
        if ($map === null || !isset($map[1])) {
            return $prices;
        }

        $dates = Settings::getArrayValue('general.available_dates', $product_id);
        if (empty($dates)) {
            return $prices;
        }

        $windowed = [];
        foreach ($dates as $row) {
            if (!empty($row['date'])) {
                $windowed[$row['date']] = (float) $map[1];
            }
        }
        return $windowed ?: $prices;
    }

    /**
     * Day-count tiers of the active window.
     *
     * @param array $tiers
     * @param int   $product_id
     * @return array
     */
    public function dayTiers($tiers, $product_id)
    {
        $map = self::activeMap($product_id);
        if ($map === null) {
            return $tiers;
        }

        $windowed = [];
        foreach ($map as $days => $price) {
            $windowed[(int) $days] = (float) $price;
        }
        ksort($windowed);
        return $windowed;
    }

    /**
     * The day-price map in force for a product right now, or null when the
     * product is not windowed day-priced.
     *
     * @param int $product_id
     * @return array|null
     */
    public static function activeMap($product_id)
    {
        static $cache = [];

        $product_id = (int) $product_id;
        if ($product_id < 1) {
            return null;
        }
        if (array_key_exists($product_id, $cache)) {
            return $cache[$product_id];
        }

        $cache[$product_id] = self::resolveMap($product_id);
        return $cache[$product_id];
    }

    private static function resolveMap($product_id)
    {
        // Both metas have to be present: the day-count tiers mark the product
        // as day-priced, the date windows say which prices are in force.
        $day_tiers = Settings::getArrayValue('general.pricing_tiers', $product_id);
        if (empty($day_tiers)) {
            return null;
        }

        /**
         * Date the window is resolved against. Y-m-d, Europe/Bratislava.
         * Empty means today - override it to preview another window.
         */
        $at = (string) apply_filters('alttag_registrations_day_price_window_date', '', $product_id);

        $tier = PricingTiers::resolve($product_id, $at !== '' ? $at : null);
        $key = is_array($tier) ? (string) ($tier['key'] ?? '') : '';

        /**
         * Day prices per date-window key. Filterable so a project can add a
         * window without editing the plugin.
         */
        $maps = (array) apply_filters(
            'alttag_registrations_day_price_windows',
            self::WINDOW_DAY_PRICES,
            $product_id
        );

        return isset($maps[$key]) && is_array($maps[$key]) ? $maps[$key] : null;
    }
}
