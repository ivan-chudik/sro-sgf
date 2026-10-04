<?php

namespace Alttag\Registrations\Selection;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Participant type selection (adult / child / senior / …).
 *
 * Product meta '_participant_types':
 *   [
 *     ['id' => 'adult', 'label' => 'Dospelý', 'price' => 5.0],
 *     ['id' => 'child', 'label' => 'Dieťa',   'price' => 0.0],
 *     …
 *   ]
 *
 * Session / participant data:
 *   selected_participant_types       = ['adult', 'child']
 *   selected_participant_types_data  = ['adult' => 2, 'child' => 1]
 */
class ParticipantTypeSelection extends AbstractSelection
{
    public function __construct()
    {
        $this->id = 'participant_types';
        $this->label = __('Participants', 'alttag-registrations');
        $this->meta_key = 'selected_participant_types';
        $this->data_meta_key = 'selected_participant_types_data';
        $this->product_meta_key = '_participant_types';
    }

    public function getLabel()
    {
        return apply_filters('alttag_registrations_participant_types_label', __('Participants', 'alttag-registrations'));
    }

    // =========================================================================
    // Product configuration
    // =========================================================================

    public function isActiveForProduct($product_id)
    {
        return !empty($this->getTypes($product_id));
    }

    public function getAvailableOptions($product_id)
    {
        $options = [];
        foreach ($this->getTypes($product_id) as $type) {
            $id = $type['id'] ?? '';
            if ($id !== '') {
                $options[$id] = $type['label'] ?? $id;
            }
        }
        return $options;
    }

    public function getOptionPrices($product_id)
    {
        $prices = [];
        foreach ($this->getTypes($product_id) as $type) {
            $id = $type['id'] ?? '';
            if ($id !== '') {
                $prices[$id] = (float) ($type['price'] ?? 0);
            }
        }
        return $prices;
    }

    public function getPricingTiers($product_id)
    {
        // No tier/bundle pricing for participant types — each type has a fixed price.
        return [];
    }

    public function getMaxPerOption($product_id)
    {
        // Each type can have many of that kind. 99999 = effectively unlimited.
        return 99999;
    }

    /**
     * Read raw types config from product meta.
     *
     * @param int $product_id
     * @return array
     */
    public function getTypes($product_id)
    {
        $raw = get_post_meta($product_id, $this->product_meta_key, true);
        if (!is_array($raw)) {
            return [];
        }

        $types = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = sanitize_key($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $min = max(0, (int) ($row['min'] ?? 0));
            $max_raw = $row['max'] ?? '';
            $max = ($max_raw === '' || $max_raw === null) ? 0 : max(0, (int) $max_raw);
            // 0 = no upper bound
            $default = isset($row['default']) ? max(0, (int) $row['default']) : $min;

            $entry = [
                'id' => $id,
                'label' => (string) ($row['label'] ?? $id),
                'price' => (float) ($row['price'] ?? 0),
                'default' => $default,
                'min' => $min,
                'max' => $max,
            ];
            if (!empty($row['tiers']) && is_array($row['tiers'])) {
                $tiers = [];
                foreach ($row['tiers'] as $days => $price) {
                    $d = (int) $days;
                    if ($d > 0) {
                        $tiers[$d] = (float) $price;
                    }
                }
                if (!empty($tiers)) {
                    $entry['tiers'] = $tiers;
                }
            }
            $types[] = $entry;
        }

        // Single source of truth for prices: the counter box, the cart total and
        // the JS config all read this. Pricing tiers hook in here so an early
        // bird shifts the per-person price instead of flattening the total.
        return apply_filters('alttag_registrations_participant_types', $types, (int) $product_id);
    }

    // =========================================================================
    // Checkout UI — render one +/- counter per type
    // =========================================================================

    public function renderCheckoutUI($product_id, $current_selections, $current_data, $product_label = '')
    {
        $types = $this->getTypes($product_id);
        if (empty($types)) {
            return;
        }

        $currency = get_woocommerce_currency_symbol();
        $session_key = $this->getSessionKey();
        $data_key = $this->getDataSessionKey();

        $theme = \Alttag\Registrations\Settings::getValue('checkout.selection_theme') ?: 'light';
        $theme_class = $theme === 'dark' ? ' participant-types--theme-dark' : '';
        echo '<div id="participant-types-wrapper" '
            . 'class="participant-types' . esc_attr($theme_class) . '" '
            . 'data-selection-type="' . esc_attr($this->id) . '">';
        echo '<h3>' . esc_html($this->getLabel()) . '</h3>';

        do_action('alttag_registrations_before_participant_type_selection', $product_id, $this);

        echo '<div class="participant-types-rows">';

        foreach ($types as $type) {
            $id = $type['id'];
            $min = (int) ($type['min'] ?? 0);
            $max = (int) ($type['max'] ?? 0);
            $count = isset($current_data[$id]) ? (int) $current_data[$id] : (int) $type['default'];
            $count = max($min, $count);
            if ($max > 0) {
                $count = min($max, $count);
            }

            $is_selected = $count > 0;

            $row_attrs = sprintf(
                ' data-type="%s" data-price="%s" data-min="%d" data-max="%d"',
                esc_attr($id),
                esc_attr($type['price']),
                $min,
                $max
            );
            if (!empty($type['price_before'])) {
                $row_attrs .= sprintf(' data-price-before="%s"', esc_attr($type['price_before']));
            }

            echo '<div class="participant-type-row' . ($is_selected ? ' checked' : '') . '"' . $row_attrs . '>';
            echo '<div class="participant-type-left">';
            echo '<span class="participant-type-label">' . esc_html($type['label']) . '</span>';
            echo '<span class="participant-type-price">';
            if ($type['price'] > 0) {
                // The price before the tier, struck through, so the discount is
                // visible right where the price is.
                if (!empty($type['price_before']) && $type['price_before'] > $type['price']) {
                    echo '<s class="participant-type-price-before">'
                        . esc_html(number_format($type['price_before'], 2, ',', ' ')) . ' ' . $currency
                        . '</s> ';
                }
                // Currency is a WC HTML entity (e.g. &euro;) — do not re-escape.
                echo esc_html(number_format($type['price'], 2, ',', ' ')) . ' ' . $currency;
            } else {
                esc_html_e('Free', 'alttag-registrations');
            }
            echo '</span>';
            echo '</div>';
            echo '<div class="participant-type-right">';
            echo '<div class="participant-type-counter">';
            echo '<button type="button" class="participants-btn participant-type-minus" data-type="'
                . esc_attr($id) . '">&minus;</button>';
            $input_max_attr = $max > 0 ? ' max="' . (int) $max . '"' : '';
            echo '<input type="number" name="' . esc_attr($data_key) . '[' . esc_attr($id) . ']"'
                . ' value="' . esc_attr($count) . '" min="' . (int) $min . '"' . $input_max_attr
                . ' class="participant-type-count-input" readonly />';
            echo '<button type="button" class="participants-btn participant-type-plus" data-type="'
                . esc_attr($id) . '">+</button>';
            echo '</div>';
            echo '</div>';
            // Hidden input mirrors the "selected" flag — keeps SelectionManager session in sync
            echo '<input type="hidden" class="participant-type-selected" name="' . esc_attr($session_key) . '[]"'
                . ' value="' . esc_attr($id) . '"' . ($is_selected ? '' : ' disabled') . ' />';
            echo '</div>';
        }

        echo '</div>';
        echo '<div class="participant-types-total"></div>';
        echo '<div class="participant-types-error"></div>';
        // Seating keeps one order together; separate orders are separate parties.
        echo '<p class="participant-types-hint">'
            . esc_html__('Register the whole family in one order - we always keep one group together. Separate orders may end up in different groups.', 'alttag-registrations')
            . '</p>';
        echo '</div>';
    }

    // =========================================================================
    // Validation: at least one participant total
    // =========================================================================

    public function validate($selections, $product_id)
    {
        $data = $this->getDataFromSession();
        $data = is_array($data) ? $data : [];
        $types = $this->getTypes($product_id);

        $total = 0;
        foreach ($types as $type) {
            $id = $type['id'];
            $count = max(0, (int) ($data[$id] ?? 0));
            $min = (int) ($type['min'] ?? 0);
            $max = (int) ($type['max'] ?? 0);

            if ($count < $min) {
                return new \WP_Error(
                    'participant_type_below_min',
                    sprintf(
                        /* translators: 1: minimum count, 2: type label */
                        __('Please select at least %1$d × %2$s.', 'alttag-registrations'),
                        $min,
                        $type['label']
                    )
                );
            }

            if ($max > 0 && $count > $max) {
                return new \WP_Error(
                    'participant_type_above_max',
                    sprintf(
                        /* translators: 1: maximum count, 2: type label */
                        __('You can select at most %1$d × %2$s.', 'alttag-registrations'),
                        $max,
                        $type['label']
                    )
                );
            }

            $total += $count;
        }

        if ($total < 1) {
            return new \WP_Error(
                'no_participants',
                __('Please select at least one participant.', 'alttag-registrations')
            );
        }
        return true;
    }

    // =========================================================================
    // Display formatting (cart / email / admin)
    // =========================================================================

    public function formatSelections($selections, $selection_data, $product_id)
    {
        $labels = $this->getAvailableOptions($product_id);
        $parts = [];
        foreach ($selection_data as $id => $count) {
            $n = (int) $count;
            if ($n <= 0) {
                continue;
            }
            $label = $labels[$id] ?? $id;
            $parts[] = $n . '× ' . $label;
        }
        return implode(', ', $parts);
    }

    // =========================================================================
    // Assets
    // =========================================================================

    public function getAssets(): array
    {
        return [
            'css' => ['css/selection-participant-types.css'],
            'js' => ['js/selection-participant-types.js'],
        ];
    }

    public function getJsConfig($product_id)
    {
        $config = parent::getJsConfig($product_id);

        // Per-type day-count tiers for matrix pricing display on checkout.
        $type_tiers = new \stdClass();
        foreach ($this->getTypes($product_id) as $type) {
            $id = $type['id'] ?? '';
            if ($id === '' || empty($type['tiers']) || !is_array($type['tiers'])) {
                continue;
            }
            $tier_obj = new \stdClass();
            foreach ($type['tiers'] as $days => $price) {
                $tier_obj->{(string) (int) $days} = (float) $price;
            }
            $type_tiers->{$id} = $tier_obj;
        }
        $config['type_tiers'] = $type_tiers;
        $config['per_person_label'] = __('person', 'alttag-registrations');
        $config['total_label'] = __('Total', 'alttag-registrations');
        $config['original_price_label'] = __('Original price', 'alttag-registrations');
        $config['discount_label'] = __('Discount (%d days)', 'alttag-registrations');

        // Prices before the active pricing tier, for the discount line in the
        // summary. Only types the tier actually made cheaper are listed.
        $prices_before = new \stdClass();
        $tier_label = '';
        foreach ($this->getTypes($product_id) as $type) {
            $id = $type['id'] ?? '';
            if ($id === '' || empty($type['price_before'])) {
                continue;
            }
            $prices_before->{$id} = (float) $type['price_before'];
            if ($tier_label === '' && !empty($type['tier_label'])) {
                $tier_label = (string) $type['tier_label'];
            }
        }
        $config['prices_before'] = $prices_before;
        $config['tier_discount_label'] = $tier_label !== ''
            ? sprintf(
                /* translators: %s: pricing tier name, e.g. Early bird */
                __('%s discount', 'alttag-registrations'),
                $tier_label
            )
            : __('Discount', 'alttag-registrations');

        return $config;
    }
}
