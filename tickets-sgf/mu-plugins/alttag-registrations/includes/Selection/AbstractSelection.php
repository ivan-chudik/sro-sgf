<?php

namespace Alttag\Registrations\Selection;

use Alttag\Registrations\ParticipantState;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base class for selection types (days, hotel, timeslots, etc.)
 *
 * To create a new selection type, extend this class and override
 * what you need. Most logic has sensible defaults.
 *
 * Minimum required:
 *   - Set $id, $label, $meta_key, $data_meta_key in constructor
 *   - Override getAvailableOptions() to define what's selectable
 *   - Override getOptionPrices() if options have per-item pricing
 *
 * Example for a new "hotel" selection:
 *
 *   class HotelSelection extends AbstractSelection {
 *       public function __construct() {
 *           $this->id = 'hotel';
 *           $this->label = 'Hotel';
 *           $this->meta_key = 'selected_hotel';
 *           $this->data_meta_key = 'selected_hotel_data';
 *       }
 *       public function getAvailableOptions($product_id) {
 *           return get_post_meta($product_id, '_hotel_options', true) ?: [];
 *       }
 *   }
 */
abstract class AbstractSelection
{
    /** @var string Unique type identifier */
    protected $id;

    /** @var string Human-readable label */
    protected $label;

    /** @var string Meta key for storing simple selections (array of keys) */
    protected $meta_key = 'selected_items';

    /** @var string Meta key for storing selections with quantities */
    protected $data_meta_key = 'selected_items_data';

    /** @var string Product meta key for available options */
    protected $product_meta_key = '';

    /**
     * Is this type's session state scoped to a single product?
     *
     * Global by default: one selection shared by the whole cart. Types where
     * two cart products have to hold different selections at the same time set
     * this to true and get their own session key and form field per product
     * (`selected_days_123`, `name="selected_days[123][]"`), so the wrappers no
     * longer pre-check each other's options, cross-contaminate the price or
     * write the same options onto both order items.
     */
    protected $scoped_by_product = false;

    // =========================================================================
    // Assets (override to provide type-specific CSS/JS)
    // =========================================================================

    /**
     * Get CSS/JS assets for this selection type.
     * Paths are relative to the plugin assets/ directory.
     *
     * @return array ['css' => ['selection-days.css'], 'js' => ['selection-days.js']]
     */
    public function getAssets(): array
    {
        return [];
    }

    // =========================================================================
    // Identity (rarely need to override)
    // =========================================================================

    public function getId()
    {
        return $this->id;
    }

    public function getLabel()
    {
        return $this->label;
    }

    public function getMetaKey()
    {
        return $this->meta_key;
    }

    public function getDataMetaKey()
    {
        return $this->data_meta_key;
    }

    /** Is the session state of this type kept per product? */
    public function isScopedByProduct(): bool
    {
        return $this->scoped_by_product;
    }

    /**
     * Session key holding the selections.
     *
     * Without a product id (or for an unscoped type) this is the bare meta key,
     * which is also the root of the posted field name.
     */
    public function getSessionKey($product_id = 0)
    {
        return $this->scopeKey($this->meta_key, $product_id);
    }

    public function getDataSessionKey($product_id = 0)
    {
        return $this->scopeKey($this->data_meta_key, $product_id);
    }

    private function scopeKey($key, $product_id)
    {
        return ($this->scoped_by_product && (int) $product_id > 0)
            ? $key . '_' . (int) $product_id
            : $key;
    }

    // =========================================================================
    // Product configuration (override these)
    // =========================================================================

    /**
     * Is this selection type active for a product?
     * Default: active if product has available options.
     */
    public function isActiveForProduct($product_id)
    {
        $options = $this->getAvailableOptions($product_id);
        return !empty($options);
    }

    /**
     * Get available options for a product.
     * MUST override this in subclass.
     *
     * @return array ['option_key' => 'Label', ...]
     */
    abstract public function getAvailableOptions($product_id);

    /**
     * Get per-option prices. Override if options have individual pricing.
     *
     * @return array ['option_key' => price, ...]
     */
    public function getOptionPrices($product_id)
    {
        return [];
    }

    /**
     * Get pricing tiers (quantity discounts). Override if needed.
     *
     * @return array [count => price_per_person, ...]
     */
    public function getPricingTiers($product_id)
    {
        return [];
    }

    /**
     * How many options the visitor must pick for this product.
     *
     * 0 = no constraint (pick any number). A positive value means the product
     * is sold for exactly that many options — the price comes from the product
     * itself, the visitor only chooses which ones.
     *
     * @return int
     */
    public function getRequiredCount($product_id)
    {
        return 0;
    }

    /** Hint shown above the options when an exact count is required. */
    protected function requiredCountHint($count, $product_id)
    {
        $default = sprintf(
            /* translators: %d: number of options the visitor has to pick. */
            _n('Choose exactly %d option.', 'Choose exactly %d options.', $count, 'alttag-registrations'),
            $count
        );

        return (string) apply_filters(
            'alttag_registrations_selection_required_hint',
            $default,
            $count,
            $product_id,
            $this
        );
    }

    /** Error shown when the visitor tries to check out with the wrong count. */
    protected function requiredCountError($count, $product_id)
    {
        $default = sprintf(
            /* translators: %d: number of options the visitor has to pick. */
            _n('Please select exactly %d option.', 'Please select exactly %d options.', $count, 'alttag-registrations'),
            $count
        );

        return (string) apply_filters(
            'alttag_registrations_selection_required_error',
            $default,
            $count,
            $product_id,
            $this
        );
    }

    /**
     * Get max persons/quantity per option. 0 = single selection.
     */
    public function getMaxPerOption($product_id)
    {
        $pc = \Alttag\Registrations\ProductConfig::get($product_id);
        return $pc ? $pc->maxParticipants() : 0;
    }

    // =========================================================================
    // Pricing calculation (override for custom pricing logic)
    // =========================================================================

    /**
     * Calculate total price from selections.
     * Default: layered tier pricing or per-option sum.
     */
    public function calculatePrice($selections, $option_prices, $tiers)
    {
        if (empty($selections)) {
            return 0;
        }

        // If tiers exist, use layered pricing
        if (!empty($tiers)) {
            return $this->calculateLayeredPrice($selections, $tiers, $option_prices);
        }

        // Otherwise sum per-option prices × quantities
        $total = 0;
        foreach ($selections as $key => $count) {
            $price = $option_prices[$key] ?? 0;
            $total += $price * (int) $count;
        }
        return $total;
    }

    /**
     * Layered tier pricing calculation.
     * Sorts person counts, calculates price per layer.
     */
    protected function calculateLayeredPrice($selections, $tiers, $option_prices)
    {
        // Sort tiers by count
        ksort($tiers);

        // Get person counts per option, sorted ascending (matches JS logic)
        $person_counts = array_values($selections);
        sort($person_counts);

        if (empty($person_counts)) {
            return 0;
        }

        $total_options = count($selections);
        $total = 0;
        $prev_count = 0;

        foreach ($person_counts as $count) {
            $layer_persons = $count - $prev_count;
            if ($layer_persons <= 0) {
                continue;
            }

            $remaining_options = 0;
            foreach ($person_counts as $pc) {
                if ($pc >= $count) {
                    $remaining_options++;
                }
            }

            // Find tier price for this many options
            $tier_price = null;
            foreach ($tiers as $tier_count => $tier_price_value) {
                if ($remaining_options >= $tier_count) {
                    $tier_price = $tier_price_value;
                }
            }

            if ($tier_price !== null) {
                $total += $layer_persons * $tier_price;
            } else {
                // No tier - sum individual option prices
                foreach ($selections as $key => $pc) {
                    if ($pc >= $count) {
                        $total += $layer_persons * ($option_prices[$key] ?? 0);
                    }
                }
            }

            $prev_count = $count;
        }

        return $total;
    }

    // =========================================================================
    // Session helpers
    // =========================================================================

    /**
     * Get selections from WC session
     */
    public function getFromSession($product_id = 0)
    {
        if (!function_exists('WC') || !WC()->session) {
            return [];
        }
        return WC()->session->get($this->getSessionKey($product_id), []);
    }

    /**
     * Get selection data from WC session
     */
    public function getDataFromSession($product_id = 0)
    {
        if (!function_exists('WC') || !WC()->session) {
            return [];
        }
        return WC()->session->get($this->getDataSessionKey($product_id), []);
    }

    // =========================================================================
    // Display formatting
    // =========================================================================

    /**
     * Format selections for display (cart, email, admin).
     * Override for custom formatting.
     */
    public function formatSelections($selections, $selection_data, $product_id)
    {
        if (empty($selections)) {
            return '';
        }

        $options = $this->getAvailableOptions($product_id);
        $parts = [];

        foreach ($selections as $key) {
            $label = $options[$key] ?? $key;
            $count = $selection_data[$key] ?? 1;
            if ($count > 1) {
                $label .= ' (' . $count . ' ' . __('persons', 'alttag-registrations') . ')';
            }
            $parts[] = $label;
        }

        return implode(', ', $parts);
    }

    /**
     * Format selections from participant state
     */
    public function formatParticipantSelections(ParticipantState $state)
    {
        $selections = $state->getMeta($this->meta_key, []);
        $data = $state->getMeta($this->data_meta_key, []);
        $product_id = (int) $state->getMeta('product_id', 0);

        if (!is_array($selections)) {
            $selections = [];
        }
        if (!is_array($data)) {
            $data = [];
        }

        return $this->formatSelections($selections, $data, $product_id);
    }

    // =========================================================================
    // Checkout UI (override for custom rendering)
    // =========================================================================

    /**
     * Render selection UI on checkout.
     * Default: checkbox list with optional per-option pricing and person counter.
     */
    public function renderCheckoutUI($product_id, $current_selections, $current_data, $product_label = '')
    {
        $options = $this->getAvailableOptions($product_id);
        if (empty($options)) {
            return;
        }

        $prices = $this->getOptionPrices($product_id);
        $pricing_rows = $this->getPricingSummaryRows($product_id);
        $max_per_option = $this->getMaxPerOption($product_id);
        $required_count = (int) $this->getRequiredCount($product_id);
        $currency = get_woocommerce_currency_symbol();
        // Field roots stay the bare keys; a scoped type nests them under the
        // product id so two wrappers post two independent selections.
        $session_key = $this->getSessionKey();
        $data_key = $this->getDataSessionKey();
        $field_scope = $this->scoped_by_product ? '[' . (int) $product_id . ']' : '';

        $theme = \Alttag\Registrations\Settings::getValue('checkout.selection_theme') ?: 'light';
        $theme_class = $theme === 'dark' ? ' day-selection--theme-dark' : '';
        $wrapper_id = 'day-selection-wrapper-' . (int) $product_id;
        // Per-wrapper pricing data so JS can compute totals correctly when
        // multiple multi-day products coexist in the cart.
        $tier_data = [];
        foreach ($this->getPricingTiers($product_id) as $days => $price) {
            $tier_data[(int) $days] = (float) $price;
        }
        echo '<div id="' . esc_attr($wrapper_id) . '"'
            . ' class="day-selection' . esc_attr($theme_class) . '"'
            . ' data-product-id="' . esc_attr($product_id) . '"'
            . ' data-day-prices="' . esc_attr(wp_json_encode((object) $prices)) . '"'
            . ' data-tiers="' . esc_attr(wp_json_encode((object) $tier_data)) . '"'
            . ' data-required-count="' . esc_attr($required_count) . '"'
            . '>';
        $heading = $this->localizedDaySelectionText('select_days');
        if ($product_label !== '') {
            $heading .= ' — ' . $product_label;
        }
        echo '<h3>' . esc_html($heading) . '</h3>';

        if ($required_count > 0) {
            echo '<p class="day-selection-required">'
                . esc_html($this->requiredCountHint($required_count, $product_id))
                . '</p>';
        }

        do_action('alttag_registrations_before_day_selection', $product_id, $this);

        if (!empty($pricing_rows)) {
            echo '<div class="day-selection-pricing">';
            echo '<div class="day-selection-pricing-title">' . esc_html($this->localizedDaySelectionText('multi_day_discounts')) . '</div>';
            echo '<div class="day-selection-pricing-list">';

            foreach ($pricing_rows as $row) {
                echo '<div class="day-selection-pricing-item" data-tier-days="' . esc_attr($row['days']) . '">';
                echo '<div class="day-selection-pricing-copy">';
                echo '<span class="day-selection-pricing-label">' . esc_html($row['label']) . '</span>';
                $instead_of_template = $this->localizedDaySelectionText('instead_of');
                $struck_price = '<s>' . esc_html($row['regular_price']) . '</s>';
                echo '<span class="day-selection-pricing-note">'
                    . wp_kses(sprintf($instead_of_template, $struck_price), ['s' => []])
                    . '</span>';
                echo '</div>';
                echo '<strong class="day-selection-pricing-price">' . esc_html($row['bundle_price']) . '</strong>';
                echo '</div>';
            }

            echo '</div>';
            echo '</div>';
        }

        echo '<div class="day-checkboxes">';

        foreach ($options as $key => $label) {
            $count = $current_data[$key] ?? 0;
            $is_selected = in_array($key, $current_selections) || $count > 0;
            $checked = $is_selected ? ' checked' : '';
            $price = $prices[$key] ?? 0;

            echo '<div class="day-checkbox' . ($is_selected ? ' checked' : '') . '" data-price="' . esc_attr($price) . '">';
            echo '<label class="day-checkbox-left">';
            echo '<input type="checkbox" name="' . esc_attr($session_key . $field_scope) . '[]" value="' . esc_attr($key) . '"' . $checked . '>';
            echo '<span class="day-checkbox-label">' . esc_html($label) . '</span>';
            echo '</label>';

            echo '<div class="day-checkbox-right">';
            if ($price > 0) {
                echo '<span class="day-price">' . esc_html(number_format($price, 2, ',', ' ')) . ' ' . esc_html($currency) . '</span>';
            }

            if ($max_per_option > 1) {
                echo '<div class="day-participants-group">';
                echo '<span class="day-participants-label">' . esc_html($this->localizedDaySelectionText('persons_label')) . '</span>';
                echo '<div class="day-participants-counter">';
                echo '<button type="button" class="participants-btn day-count-minus" data-date="' . esc_attr($key) . '">&minus;</button>';
                echo '<input type="number" name="' . esc_attr($data_key . $field_scope) . '[' . esc_attr($key) . ']" value="' . esc_attr(max(1, $count)) . '" min="1" max="' . esc_attr($max_per_option) . '" class="day-count-input" readonly />';
                echo '<button type="button" class="participants-btn day-count-plus" data-date="' . esc_attr($key) . '">+</button>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
            echo '</div>';
        }

        echo '</div>';
        echo '<div class="day-selection-total"></div>';
        echo '<div class="day-selection-error"></div>';
        echo '</div>';
    }

    protected function getPricingSummaryRows($product_id)
    {
        $tiers = $this->getPricingTiers($product_id);
        $option_prices = $this->getPricingSummaryOptionPrices($product_id);
        $regular_tiers = $this->getPricingSummaryRegularTiers($product_id);
        $option_count = count($this->getAvailableOptions($product_id));

        if (empty($tiers) || empty($option_prices) || $option_count < 2) {
            return [];
        }

        ksort($tiers);

        $rows = [];
        $currency = get_woocommerce_currency_symbol();

        foreach ($tiers as $days => $tier_price) {
            $days = (int) $days;
            if ($days < 2 || $days > $option_count) {
                continue;
            }

            $has_regular_tier = isset($regular_tiers[$days]);
            $range = $has_regular_tier
                ? [
                    'min' => (float) $regular_tiers[$days],
                    'max' => (float) $regular_tiers[$days],
                ]
                : $this->getRegularPriceRange($days, $option_prices);
            if ($range === null) {
                continue;
            }

            // Skip tiers that are not actually discounted
            if ((float) $tier_price > $range['max']
                || (!$has_regular_tier && (float) $tier_price >= $range['max'])) {
                continue;
            }

            $regular_price = $this->formatPriceForDisplay($range['min'], $currency);
            if ($range['max'] !== $range['min']) {
                $regular_price .= ' - ' . $this->formatPriceForDisplay($range['max'], $currency);
            }

            if ($days === $option_count && $option_count === 2) {
                $label = $this->localizedDaySelectionText('both_days_label');
            } elseif ($days === $option_count) {
                $label = sprintf(
                    $this->localizedDaySelectionText('all_days_label'),
                    $days,
                    $this->localizedDaySelectionText('days')
                );
            } else {
                $label = sprintf(_n('%d day', '%d days', $days, 'alttag-registrations'), $days);
            }

            $rows[] = [
                'days' => $days,
                'label' => $label,
                'bundle_price' => $this->formatPriceForDisplay((float) $tier_price, $currency),
                'regular_price' => $regular_price,
            ];
        }

        return $rows;
    }

    /** Prices used for the struck total in the pricing summary. */
    protected function getPricingSummaryOptionPrices($product_id)
    {
        return $this->getOptionPrices($product_id);
    }

    /** Bundle prices used for the struck total, keyed by option count. */
    protected function getPricingSummaryRegularTiers($product_id)
    {
        return [];
    }

    protected function getRegularPriceRange($days, array $option_prices)
    {
        $prices = array_values(array_map('floatval', $option_prices));
        sort($prices, SORT_NUMERIC);

        if ($days < 1 || $days > count($prices)) {
            return null;
        }

        return [
            'min' => array_sum(array_slice($prices, 0, $days)),
            'max' => array_sum(array_slice($prices, -$days)),
        ];
    }

    protected function formatPriceForDisplay($amount, $currency)
    {
        return number_format((float) $amount, 2, ',', ' ') . ' ' . $currency;
    }

    protected function localizedDaySelectionText($key)
    {
        $strings = [
            'select_days' => __('Select days', 'alttag-registrations'),
            'multi_day_discounts' => __('Multi-day discounts', 'alttag-registrations'),
            'instead_of' => __('instead of %s', 'alttag-registrations'),
            'persons_label' => __('Persons:', 'alttag-registrations'),
            'days' => __('days', 'alttag-registrations'),
            'all_days_label' => __('All %1$d %2$s', 'alttag-registrations'),
            'both_days_label' => __('Both days', 'alttag-registrations'),
        ];

        return $strings[$key] ?? '';
    }

    // =========================================================================
    // Validation
    // =========================================================================

    /**
     * Validate selections. Override for custom validation.
     * Default: at least one option must be selected.
     */
    public function validate($selections, $product_id)
    {
        if (empty($selections)) {
            return new \WP_Error(
                'no_selection',
                sprintf(
                    __('Please select at least one %s.', 'alttag-registrations'),
                    function_exists('mb_strtolower')
                        ? mb_strtolower($this->getLabel(), 'UTF-8')
                        : strtolower($this->getLabel())
                )
            );
        }

        $required = (int) $this->getRequiredCount($product_id);
        if ($required > 0 && count($selections) !== $required) {
            return new \WP_Error(
                'wrong_selection_count',
                $this->requiredCountError($required, $product_id)
            );
        }

        // Validate all selections are valid options
        $available = array_keys($this->getAvailableOptions($product_id));
        foreach ($selections as $key) {
            if (!in_array($key, $available)) {
                return new \WP_Error(
                    'invalid_selection',
                    sprintf(__('Invalid selection: %s', 'alttag-registrations'), $key)
                );
            }
        }

        return true;
    }

    // =========================================================================
    // JS config
    // =========================================================================

    /**
     * Get JS configuration. Override to add type-specific config.
     */
    public function getJsConfig($product_id)
    {
        return [
            'type' => $this->id,
            'options' => $this->getAvailableOptions($product_id),
            'prices' => (object) $this->getOptionPrices($product_id),
            'tiers' => (object) $this->getPricingTiers($product_id),
            'max_per_option' => $this->getMaxPerOption($product_id),
            'required_count' => (int) $this->getRequiredCount($product_id),
            'currency' => get_woocommerce_currency_symbol(),
            'session_key' => $this->getSessionKey($product_id),
            'data_session_key' => $this->getDataSessionKey($product_id),
            'label' => $this->getLabel(),
        ];
    }
}
