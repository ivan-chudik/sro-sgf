<?php

namespace Alttag\Registrations\Selection;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Day-based event selection (multi-day registration).
 * Reads available dates and pricing from product meta.
 */
class DaySelection extends AbstractSelection
{
    /**
     * Two day products can sit in one cart (livestream + in-person, SK + EN),
     * and each has to keep its own days - see AbstractSelection::$scoped_by_product.
     */
    protected $scoped_by_product = true;

    public function __construct()
    {
        $this->id = 'days';
        $this->label = 'Select days'; // Translated lazily in getLabel()
        $this->meta_key = 'selected_days';
        $this->data_meta_key = 'selected_days_data';
    }

    /**
     * The constructor runs while the mu-plugin file is being required, i.e.
     * before the textdomain is loaded on `plugins_loaded`. Translate lazily.
     */
    public function getLabel()
    {
        return apply_filters('alttag_registrations_day_selection_label', __('Select days', 'alttag-registrations'));
    }

    public function getAvailableOptions($product_id)
    {
        $dates = \Alttag\Registrations\Settings::getArrayValue('general.available_dates', $product_id);
        if (empty($dates)) {
            return [];
        }

        $result = [];
        foreach ($dates as $row) {
            if (!empty($row['date'])) {
                $result[$row['date']] = $row['label'] ?? $row['date'];
            }
        }
        return $result;
    }

    public function getOptionPrices($product_id)
    {
        $prices = $this->getBaseOptionPrices($product_id);
        $tier_prices = $this->getDisplayTierPrices($product_id);

        // Per-date differences remain meaningful when there is no generic day
        // tier map to replace. Otherwise the active tier's one-day price is the
        // display basis for every date.
        if (!$tier_prices || !isset($tier_prices[1]) || !$this->getBasePricingTiers($product_id)) {
            return $prices;
        }

        return array_fill_keys(array_keys($prices), (float) $tier_prices[1]);
    }

    private function getBaseOptionPrices($product_id)
    {
        $dates = \Alttag\Registrations\Settings::getArrayValue('general.available_dates', $product_id);
        if (empty($dates)) {
            return [];
        }

        $prices = [];
        foreach ($dates as $row) {
            if (!empty($row['date']) && isset($row['price'])) {
                $prices[$row['date']] = (float) $row['price'];
            }
        }

        return $prices;
    }

    public function getPricingTiers($product_id)
    {
        $tier_prices = $this->getDisplayTierPrices($product_id);
        return $tier_prices ?: $this->getBasePricingTiers($product_id);
    }

    private function getBasePricingTiers($product_id)
    {
        $tiers_raw = \Alttag\Registrations\Settings::getArrayValue('general.pricing_tiers', $product_id);
        if (empty($tiers_raw)) {
            return [];
        }

        $tiers = [];
        foreach ($tiers_raw as $tier) {
            if (isset($tier['days']) && isset($tier['price'])) {
                $tiers[(int) $tier['days']] = (float) $tier['price'];
            }
        }
        ksort($tiers);

        return $tiers;
    }

    /**
     * Resolve the one participant type whose active tier can be represented by
     * the flat day-selection price map.
     */
    private function getDisplayTierPrices($product_id)
    {
        static $prices_by_product = [];

        $product_id = (int) $product_id;
        if (array_key_exists($product_id, $prices_by_product)) {
            return $prices_by_product[$product_id];
        }

        $prices_by_product[$product_id] = [];
        $tier = \Alttag\Registrations\PricingTiers::resolve($product_id);
        if (!$tier || empty($tier['type_day_prices']) || !is_array($tier['type_day_prices'])) {
            return [];
        }

        $type_selection = SelectionManager::getType('participant_types');
        if (!$type_selection || !method_exists($type_selection, 'getTypes')) {
            return [];
        }

        $matches = [];
        foreach ($type_selection->getTypes($product_id) as $type) {
            $id = $type['id'] ?? '';
            if ($id !== '' && !empty($tier['type_day_prices'][$id])
                && is_array($tier['type_day_prices'][$id])) {
                $matches[] = $tier['type_day_prices'][$id];
            }
        }
        if (count($matches) !== 1) {
            return [];
        }

        foreach ($matches[0] as $days => $price) {
            $days = (int) $days;
            if ($days > 0) {
                $prices_by_product[$product_id][$days] = (float) $price;
            }
        }
        ksort($prices_by_product[$product_id]);

        return $prices_by_product[$product_id];
    }

    protected function getPricingSummaryOptionPrices($product_id)
    {
        return $this->getDisplayTierPrices($product_id)
            ? $this->getBaseOptionPrices($product_id)
            : parent::getPricingSummaryOptionPrices($product_id);
    }

    protected function getPricingSummaryRegularTiers($product_id)
    {
        return $this->getDisplayTierPrices($product_id)
            ? $this->getBasePricingTiers($product_id)
            : [];
    }

    public function isActiveForProduct($product_id)
    {
        return parent::isActiveForProduct($product_id);
    }

    public function validate($selections, $product_id)
    {
        if (empty($selections)) {
            return new \WP_Error(
                'no_selection',
                __('Please select at least one day.', 'alttag-registrations')
            );
        }
        return parent::validate($selections, $product_id);
    }

    /**
     * Number of days this product is sold for (1-day ticket, 2-day ticket, ...).
     *
     * Set per product; empty or 0 means the visitor picks any number of days
     * and the per-day/tier prices decide the total. With a fixed count the
     * product's own WooCommerce price (including a scheduled sale) is the
     * price, and the picker only asks which days.
     */
    public function getRequiredCount($product_id)
    {
        $value = \Alttag\Registrations\Settings::getValue('general.required_days', $product_id);
        return max(0, (int) $value);
    }

    protected function requiredCountHint($count, $product_id)
    {
        $default = sprintf(
            /* translators: %d: number of days the visitor has to pick. */
            _n('Choose exactly %d day.', 'Choose exactly %d days.', $count, 'alttag-registrations'),
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

    protected function requiredCountError($count, $product_id)
    {
        $default = sprintf(
            /* translators: %d: number of days the visitor has to pick. */
            _n('Please select exactly %d day.', 'Please select exactly %d days.', $count, 'alttag-registrations'),
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

    public function getMaxPerOption($product_id)
    {
        $value = \Alttag\Registrations\Settings::getValue('general.max_persons_per_day', $product_id);
        $max = (int) $value;
        // 0 or empty = "no counter" per Settings description; max=1 means the
        // renderer skips the per-day persons UI.
        return $max > 0 ? $max : 1;
    }

    /**
     * Get option labels for a participant (from their product)
     */
    public function getParticipantOptionLabels($participant_id)
    {
        $state = \Alttag\Registrations\ParticipantState::get($participant_id);
        $product_id = $state ? (int) $state->getMeta('product_id') : 0;
        return $product_id ? $this->getAvailableOptions($product_id) : [];
    }

    public function getAssets(): array
    {
        return [
            'css' => ['css/selection-days.css'],
            'js' => ['js/selection-days.js'],
        ];
    }

    public function getJsConfig($product_id)
    {
        $config = parent::getJsConfig($product_id);
        $config['select_days_text'] = __('Select days', 'alttag-registrations');
        $config['total_label'] = __('Total', 'alttag-registrations');
        $config['days_label'] = __('days', 'alttag-registrations');
        $config['is_livestream'] = \Alttag\Registrations\product_is_livestream($product_id);
        return $config;
    }
}
