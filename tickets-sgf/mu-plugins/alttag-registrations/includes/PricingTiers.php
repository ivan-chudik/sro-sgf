<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

class PricingTiers
{
    public const META_KEY = '_alttag_pricing_tiers', ITEM_KEY = '_alttag_pricing_tier_key', ITEM_LABEL = '_alttag_pricing_tier_label', ITEM_PRICE = '_alttag_pricing_tier_price';
    public function init()
    {
        add_action('woocommerce_product_options_pricing', [$this,'fields']);
        add_action('woocommerce_process_product_meta', [$this,'save']);
        add_filter('woocommerce_is_purchasable', [$this,'purchasable'], 20, 2);
        add_filter('woocommerce_add_to_cart_validation', [$this,'validate'], 20, 3);
        add_filter('woocommerce_add_cart_item_data', [$this,'cartData'], 20, 3);
        add_action('woocommerce_before_calculate_totals', [$this,'prices'], 20);
        add_filter('alttag_registrations_participant_types', [$this,'tierTypePrices'], 10, 2);
        add_action('woocommerce_check_cart_items', [$this,'checkCart']);
        add_action('woocommerce_checkout_create_order_line_item', [$this,'orderItem'], 20, 4);
        add_filter('woocommerce_cart_item_name', [$this,'cartItemName'], 20, 3);
        add_filter('woocommerce_get_item_data', [$this,'itemData'], 30, 2);
        add_filter('woocommerce_order_item_name', [$this,'orderItemName'], 20, 3);
        add_filter('woocommerce_product_get_name', [$this,'productName'], 20, 2);
        add_filter('woocommerce_order_item_get_name', [$this,'storeApiOrderItemName'], 20, 2);
        add_filter('alttag_registrations_order_data', [$this,'orderData'], 20, 2);
        add_filter('alttag_registrations_meta_fields', [$this,'participantFields']);
        add_filter('alttag_registrations_participant_columns', [$this,'columns']);
        add_filter('alttag_registrations_participant_column_content', [$this,'column'], 20, 3);
        add_action('alttag_registrations_participant_after_filters', [$this,'filter']);
        add_filter('alttag_registrations_participant_filters_query', [$this,'filterQuery']);
        add_filter('alttag_registrations_participant_filter_arg_names', [$this,'filterArgs']);
        add_filter('manage_edit-product_columns', [$this,'addTierColumn'], 20);
        add_action('manage_product_posts_custom_column', [$this,'renderTierColumn'], 20, 2);
        add_action('admin_enqueue_scripts', [$this,'adminCss']);
    }
    public function addTierColumn(array $columns): array
    {
        $o = [];
        foreach ($columns as $k => $v) {
            $o[$k] = $v;
            if ($k === 'price') {
                $o['tier_price'] = __('Current price (tier)', 'alttag-registrations');
            }
        }if (!isset($o['tier_price'])) {
            $o['tier_price'] = __('Current price (tier)', 'alttag-registrations');
        }return$o;
    }
    public function renderTierColumn(string $column, int $post_id): void
    {
        if ($column !== 'tier_price') {
            return;
        }if (get_post_type($post_id) !== 'product') {
            echo '-';
            return;
        }$t = self::resolve($post_id);
        if (!$t) {
            echo '-';
            return;
        }$price = function_exists('wc_price') ? wc_price(wc_format_decimal($t['price'])) : esc_html($t['price']);
        echo '<strong>'.wp_kses_post($price).'</strong> <small class="alttag-tier-label">('.esc_html($t['label']).')</small>';
    }
    /**
     * Admin CSS: the "Current price (tier)" product list column and the pricing
     * tier editor in the product's General tab.
     *
     * Hooked on admin_enqueue_scripts (not admin_head-*), which is the point
     * where enqueued styles are still printed in <head> on both screens.
     */
    public function adminCss()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['edit-product', 'product'], true)) {
            return;
        }
        $css_path = ALTTAG_REGISTRATIONS_PATH . 'assets/css/admin-products.css';
        wp_enqueue_style(
            'alttag-admin-products',
            ALTTAG_REGISTRATIONS_URL . 'assets/css/admin-products.css',
            [],
            file_exists($css_path) ? (string) filemtime($css_path) : '1.0.0'
        );
    }
    public static function tiers($id)
    {
        $v = get_post_meta((int)$id, self::META_KEY, true);
        return is_array($v) ? array_values(array_filter(array_map([self::class,'sanitize'], $v))) : [];
    }
    public static function sanitize($t)
    {
        if (!is_array($t)) {
            return null;
        } $key = sanitize_key($t['key'] ?? '');
        $label = sanitize_text_field($t['label'] ?? '');
        $price = wc_format_decimal($t['price'] ?? '');
        $from = self::date($t['date_from'] ?? '');
        $to = self::date($t['date_to'] ?? '');
        $status = sanitize_key($t['status'] ?? 'scheduled');
        if (!in_array($status, ['active','scheduled','disabled'], true)) {
            $status = 'scheduled';
        }
        $type_prices = [];
        if (!empty($t['type_prices']) && is_array($t['type_prices'])) {
            foreach ($t['type_prices'] as $type_id => $type_price) {
                $id = sanitize_key($type_id);
                $val = wc_format_decimal($type_price);
                if ($id !== '' && $val !== '') {
                    $type_prices[$id] = $val;
                }
            }
        }
        $type_day_prices = [];
        if (!empty($t['type_day_prices']) && is_array($t['type_day_prices'])) {
            foreach ($t['type_day_prices'] as $type_id => $day_prices) {
                $id = sanitize_key($type_id);
                if ($id === '' || !is_array($day_prices)) {
                    continue;
                }
                foreach ($day_prices as $day_count => $day_price) {
                    $day_count = absint($day_count);
                    $val = wc_format_decimal($day_price);
                    if ($day_count > 0 && $val !== '') {
                        $type_day_prices[$id][$day_count] = $val;
                    }
                }
            }
        }
        if (!$key || !$label || $price === '' || !$from || !$to || $from > $to) {
            return null;
        }
        $limit = isset($t['limit']) ? max(0, (int) $t['limit']) : 0;
        $row = ['key' => $key,'label' => $label,'price' => $price,'date_from' => $from,'date_to' => $to,'status' => $status,'limit' => $limit];
        if ($type_prices) {
            $row['type_prices'] = $type_prices;
        }
        if ($type_day_prices) {
            $row['type_day_prices'] = $type_day_prices;
        }
        return $row;
    }
    private static function date($d)
    {
        $d = sanitize_text_field((string)$d);
        $x = \DateTimeImmutable::createFromFormat('!Y-m-d', $d, new \DateTimeZone('Europe/Bratislava'));
        return $x && $x->format('Y-m-d') === $d ? $d : '';
    }
    public static function resolve($id, $at = null)
    {
        $tz = new \DateTimeZone('Europe/Bratislava');
        $day = is_string($at) && self::date($at) ? $at : ($at instanceof \DateTimeInterface ? (new \DateTimeImmutable($at->format('c')))->setTimezone($tz)->format('Y-m-d') : (new \DateTimeImmutable('now', $tz))->format('Y-m-d'));
        $override = null;
        foreach (self::tiers($id) as $t) {
            if ($t['status'] === 'disabled') {
                continue;
            }
            // Sold-out tier is skipped, the price falls through to the next one (earlybird -> full).
            if ($day >= $t['date_from'] && $day <= $t['date_to']) {
                if (self::isExhausted($id, $t)) {
                    continue;
                }
                return $t;
            }if ($t['status'] === 'active' && $day < $t['date_from'] && !$override) {
                $override = $t;
            }
        }if ($override) {
            return $override;
        }return self::productPriceFallback($id, $day);
    }
    /**
     * The product's own price as an implicit final tier.
     *
     * When every tier is used up and there is no full price tier left to
     * advance to, resolve() would close registration even though the product
     * itself still carries a price. Sell at that price instead.
     *
     * Returns null - and registration stays closed exactly as before - when the
     * product has no tiers at all, when every tier window is already over (an
     * event that is done stays done), when the product has no usable price, or
     * when the site opts out through the filter.
     *
     * @param int    $id
     * @param string $day Y-m-d in Europe/Bratislava.
     */
    private static function productPriceFallback($id, $day)
    {
        $tiers = self::tiers($id);
        if (!$tiers || !apply_filters('alttag_registrations_pricing_fallback_to_product_price', true, $id)) {
            return null;
        }
        $open = false;
        foreach ($tiers as $t) {
            if ($t['status'] !== 'disabled' && $day <= $t['date_to']) {
                $open = true;
                break;
            }
        }
        if (!$open) {
            return null;
        }
        $price = self::productPrice($id);
        if ($price === null) {
            return null;
        }
        // No limit: this is the last price there is, it cannot run out.
        return ['key' => 'full','label' => __('Full price', 'alttag-registrations'),'price' => $price,'date_from' => $day,'date_to' => $day,'status' => 'active','limit' => 0,'type_prices' => []];
    }
    /** The product's regular price, falling back to its current price. */
    private static function productPrice($id)
    {
        $product = function_exists('wc_get_product') ? wc_get_product((int) $id) : null;
        foreach ($product ? [$product->get_regular_price('edit'), $product->get_price('edit')] : [get_post_meta((int) $id, '_regular_price', true), get_post_meta((int) $id, '_price', true)] as $price) {
            if (is_numeric($price) && (float) $price > 0) {
                return wc_format_decimal($price);
            }
        }
        return null;
    }
    /**
     * Is this tier used up?
     *
     * A tier without a limit never runs out. Otherwise the number of tickets
     * already sold under it decides.
     *
     * @param int   $product_id
     * @param array $tier
     */
    public static function isExhausted($product_id, array $tier): bool
    {
        $limit = (int) ($tier['limit'] ?? 0);
        if ($limit < 1) {
            return false;
        }

        return self::soldTickets($product_id, (string) $tier['key']) >= $limit;
    }

    /**
     * Tickets sold under a pricing tier.
     *
     * People, not orders: with a price per person one person is one ticket, so a
     * family of four uses four of the limit. Cancelled and failed orders release
     * their tickets back.
     *
     * @param int    $product_id
     * @param string $tier_key
     */
    public static function soldTickets($product_id, string $tier_key): int
    {
        static $cache = [];

        $product_id = (int) $product_id;
        $cache_key = $product_id . '|' . $tier_key;
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        $statuses = array_values(array_diff(
            array_keys(wc_get_order_statuses()),
            ['wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-checkout-draft']
        ));

        $orders = wc_get_orders([
            'limit' => -1,
            'status' => $statuses,
            'return' => 'objects',
        ]);

        $people = 0;
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                if ((int) $item->get_product_id() !== $product_id) {
                    continue;
                }
                if ((string) $item->get_meta(self::ITEM_KEY) !== $tier_key) {
                    continue;
                }

                $types = $item->get_meta('selected_participant_types_data');
                if (is_array($types) && $types) {
                    $people += array_sum(array_map('intval', $types));
                    continue;
                }

                $people += max(1, (int) $item->get_quantity());
            }
        }

        $people = (int) apply_filters(
            'alttag_registrations_pricing_tier_sold_tickets',
            $people,
            $product_id,
            $tier_key
        );

        $cache[$cache_key] = $people;

        return $people;
    }

    /** Tickets still available in a tier, or null when it has no limit. */
    public static function remainingTickets($product_id, array $tier): ?int
    {
        $limit = (int) ($tier['limit'] ?? 0);
        if ($limit < 1) {
            return null;
        }

        return max(0, $limit - self::soldTickets($product_id, (string) $tier['key']));
    }

    public function fields()
    {
        global $post;
        $rows = self::tiers($post ? $post->ID : 0) ?: [['key' => '','label' => '','price' => '','date_from' => '','date_to' => '','status' => 'scheduled']];
        echo '<div class="options_group alttag-tiers-group">';
        echo '<p class="alttag-tiers-intro"><strong>'.esc_html__('Registration pricing tiers', 'alttag-registrations').'</strong><br>'
            .'<span class="description">'.esc_html__('Active is a fallback outside dates; Scheduled follows dates; Disabled is unavailable. Ticket limit ends a tier once that many tickets are sold, even inside its dates.', 'alttag-registrations').'</span></p>';
        echo '<div id="alttag-tiers">';
        foreach ($rows as $i => $t) {
            $this->row($i, $t);
        }
        echo '</div>';
        echo '<p class="alttag-tiers-actions"><button type="button" class="button" id="alttag-add-tier">'.esc_html__('Add pricing tier', 'alttag-registrations').'</button></p>';
        echo '</div>';?>
<script>jQuery(function($){$('#alttag-add-tier').on('click',function(){let i=$('#alttag-tiers .alttag-tier').length,r=$('#alttag-tiers .alttag-tier').first().clone();r.find('input').val('');r.find('select').val('scheduled');r.find('[name]').each(function(){this.name=this.name.replace(/\[\d+\]/,'['+i+']')});$('#alttag-tiers').append(r)});$(document).on('click','.alttag-tier-remove',function(){$(this).closest('.alttag-tier').remove()})});</script><?php }

    /**
     * One tier as a self-contained block: labelled fields in a grid, the per
     * participant type prices separated below them.
     *
     * The name attributes (and therefore save()/sanitize()) are unchanged; only
     * the markup around them is. #alttag-tiers > .alttag-tier stays the unit the
     * add/remove JS in fields() clones and deletes.
     */
    private function row($i, $t)
    {
        $fields = [
            'key' => ['label' => __('Key', 'alttag-registrations'), 'type' => 'text'],
            'label' => ['label' => __('Label', 'alttag-registrations'), 'type' => 'text'],
            'price' => ['label' => __('Price', 'alttag-registrations'), 'type' => 'number', 'attrs' => ' min="0" step="0.01"'],
            'limit' => [
                'label' => __('Ticket limit', 'alttag-registrations'),
                'type' => 'number',
                'attrs' => ' min="0" step="1"',
                'hint' => __('Number of tickets at this price. 0 = no limit.', 'alttag-registrations'),
            ],
            'date_from' => ['label' => __('From', 'alttag-registrations'), 'type' => 'date'],
            'date_to' => ['label' => __('To', 'alttag-registrations'), 'type' => 'date'],
        ];

        echo '<div class="alttag-tier">';
        echo '<div class="alttag-tier__head">'
            .'<span class="alttag-tier__heading">'.esc_html__('Pricing tier', 'alttag-registrations').'</span>'
            .'<button type="button" class="button-link-delete alttag-tier-remove">'.esc_html__('Remove', 'alttag-registrations').'</button>'
            .'</div>';

        echo '<div class="alttag-tier__fields">';
        foreach ($fields as $k => $f) {
            echo '<label class="alttag-tier__field alttag-tier__field--'.esc_attr(str_replace('_', '-', $k)).'">'
                .'<span class="alttag-tier__label">'.esc_html($f['label']).'</span>'
                .'<input type="'.esc_attr($f['type']).'"'
                .' name="alttag_pricing_tiers['.(int)$i.']['.esc_attr($k).']"'
                .' value="'.esc_attr($t[$k] ?? '').'"'
                .($f['attrs'] ?? '')
                .(isset($f['hint']) ? ' title="'.esc_attr($f['hint']).'"' : '')
                .'></label>';
        }
        echo '<label class="alttag-tier__field alttag-tier__field--status">'
            .'<span class="alttag-tier__label">'.esc_html__('Status', 'alttag-registrations').'</span>'
            .'<select name="alttag_pricing_tiers['.(int)$i.'][status]">';
        foreach ([
            'active' => __('Active', 'alttag-registrations'),
            'scheduled' => __('Scheduled', 'alttag-registrations'),
            'disabled' => __('Disabled', 'alttag-registrations'),
        ] as $v => $l) {
            echo '<option value="'.esc_attr($v).'" '.selected($t['status'] ?? '', $v, false).'>'.esc_html($l).'</option>';
        }
        echo '</select></label>';
        echo '</div>';

        $this->typePriceInputs($i, $t);
        echo '</div>';
    }

    /**
     * Per participant type prices for this tier.
     *
     * Only rendered when the product actually has participant types, otherwise
     * the flat tier price is the whole story.
     */
    private function typePriceInputs($i, $t)
    {
        global $post;
        $types = $post ? get_post_meta($post->ID, '_participant_types', true) : [];
        if (!is_array($types) || !$types) {
            return;
        }
        $inputs = '';
        foreach ($types as $type) {
            $id = sanitize_key($type['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $label = (string) ($type['label'] ?? $id);
            $day_tiers = is_array($type['tiers'] ?? null) ? $type['tiers'] : [];
            if ($day_tiers) {
                foreach ($day_tiers as $day_count => $base_price) {
                    $day_count = absint($day_count);
                    if ($day_count < 1) {
                        continue;
                    }
                    $day_label = sprintf(
                        _n('%d day', '%d days', $day_count, 'alttag-registrations'),
                        $day_count
                    );
                    $value = $t['type_day_prices'][$id][$day_count] ?? '';
                    $inputs .= '<label class="alttag-tier__field alttag-tier__field--type-price">'
                        .'<span class="alttag-tier__label">'.esc_html($label.' - '.$day_label).'</span>'
                        .'<input type="number" min="0" step="0.01"'
                        .' name="alttag_pricing_tiers['.(int)$i.'][type_day_prices]['.esc_attr($id).']['.$day_count.']"'
                        .' value="'.esc_attr($value).'"'
                        .' placeholder="'.esc_attr(wc_format_localized_price($base_price)).'">'
                        .'</label>';
                }
                continue;
            }
            $value = $t['type_prices'][$id] ?? '';
            $inputs .= '<label class="alttag-tier__field alttag-tier__field--type-price">'
                .'<span class="alttag-tier__label">'.esc_html($label).'</span>'
                .'<input type="number" min="0" step="0.01"'
                .' name="alttag_pricing_tiers['.(int)$i.'][type_prices]['.esc_attr($id).']"'
                .' value="'.esc_attr($value).'">'
                .'</label>';
        }
        if ($inputs === '') {
            return;
        }
        echo '<div class="alttag-tier__types">'
            .'<span class="alttag-tier__types-heading">'.esc_html__('Price per participant type', 'alttag-registrations').'</span>'
            .'<div class="alttag-tier__fields">'.$inputs.'</div>'
            .'</div>';
    }
    public function save($id)
    {
        if (!isset($_POST['alttag_pricing_tiers']) || !is_array($_POST['alttag_pricing_tiers'])) {
            delete_post_meta($id, self::META_KEY);
            return;
        }update_post_meta($id, self::META_KEY, array_values(array_filter(array_map([self::class,'sanitize'], wp_unslash($_POST['alttag_pricing_tiers'])))));
    }
    public function purchasable($ok, $p)
    {
        return self::tiers($p->get_id()) ? (bool)self::resolve($p->get_id()) : $ok;
    }
    public function validate($ok, $id, $qty)
    {
        if (self::tiers($id) && !self::resolve($id)) {
            wc_add_notice(apply_filters('alttag_registrations_pricing_closed_message', __('Registration is currently not available for any pricing tier.', 'alttag-registrations'), $id), 'error');
            return false;
        }return $ok;
    }
    public function cartData($d, $id, $vid)
    {
        $t = self::resolve($vid ?: $id);
        if ($t) {
            $d['alttag_pricing_tier'] = array_intersect_key($t, array_flip(['key','label','price','type_prices','type_day_prices']));
        }return $d;
    }
    public function prices($cart)
    {
        foreach ($cart->get_cart() as $i) {
            if (!isset($i['alttag_pricing_tier']['price'],$i['data'])) {
                continue;
            }
            // A product priced per selected option gets its total from
            // SelectionManager. Setting the flat tier price here would wipe that
            // sum out, so for those the tier only shifts the per-option prices
            // (see tierTypePrices()).
            if (self::pricedPerSelection((int)($i['product_id'] ?? 0))) {
                continue;
            }
            $i['data']->set_price($i['alttag_pricing_tier']['price']);
        }
    }

    /** Does this product's price come from the visitor's selection? */
    public static function pricedPerSelection($product_id): bool
    {
        if (!$product_id || !class_exists(Selection\SelectionManager::class)) {
            return false;
        }
        foreach (Selection\SelectionManager::getActiveForProduct($product_id) as $type) {
            $prices = $type->getOptionPrices($product_id);
            if (is_array($prices) && array_filter($prices)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Let the active tier override the price of each participant type.
     *
     * @param array $types
     * @param int   $product_id
     * @return array
     */
    public function tierTypePrices($types, $product_id)
    {
        if (!is_array($types) || !$types) {
            return $types;
        }
        $tier = self::resolve($product_id);
        if (!$tier || (empty($tier['type_prices']) && empty($tier['type_day_prices']))) {
            return $types;
        }
        foreach ($types as $i => $type) {
            $id = $type['id'] ?? '';
            if ($id === '') {
                continue;
            }

            $before = (float) ($type['price'] ?? 0);
            $now = isset($tier['type_prices'][$id]) ? (float) $tier['type_prices'][$id] : $before;
            if (isset($tier['type_prices'][$id])) {
                $types[$i]['price'] = $now;
            }
            if (!empty($tier['type_day_prices'][$id]) && is_array($tier['type_day_prices'][$id])) {
                $types[$i]['tiers'] = array_map('floatval', $tier['type_day_prices'][$id]);
                $now = min($types[$i]['tiers']);
            }

            // Remember what it would cost without this tier, so the checkout can
            // show the customer what the tier saves them.
            if ($before > $now) {
                $types[$i]['price_before'] = $before;
                $types[$i]['tier_label'] = (string) ($tier['label'] ?? '');
            }
        }
        return $types;
    }
    public function checkCart()
    {
        if (!WC()->cart) {
            return;
        }foreach (WC()->cart->get_cart() as $i) {
            if (self::tiers($i['product_id'] ?? 0) && empty($i['alttag_pricing_tier'])) {
                wc_add_notice(__('A registration price could not be selected. Remove the product and add it again.', 'alttag-registrations'), 'error');
            }
        }
    }
    public function orderItem($item, $key, $values, $order)
    {
        $t = $values['alttag_pricing_tier'] ?? null;
        if ($t) {
            $item->add_meta_data(self::ITEM_KEY, $t['key'], true);
            $item->add_meta_data(self::ITEM_LABEL, $t['label'], true);
            $item->add_meta_data(self::ITEM_PRICE, wc_format_decimal($t['price']), true);
        }
    }
    public function itemData($d, $i)
    {
        $t = $i['alttag_pricing_tier'] ?? null;
        if ($t && apply_filters('alttag_registrations_display_pricing_tier_cart_item_data', false, $i)) {
            $d[] = ['key' => apply_filters('alttag_registrations_pricing_tier_label', __('Pricing tier', 'alttag-registrations')),'value' => $t['label']];
        }
        return $d;
    }

    public function cartItemName($name, $cartItem, $cartItemKey)
    {
        $tier = $cartItem['alttag_pricing_tier'] ?? null;
        return $this->appendTierLabel($name, $tier['label'] ?? '');
    }
    public function orderItemName($name, $item, $isVisible)
    {
        if (is_admin() || wp_doing_cron() || doing_action('woocommerce_email_order_details') || (defined('REST_REQUEST') && REST_REQUEST)) {
            return $name;
        }
        return $this->appendTierLabel($name, $item->get_meta(self::ITEM_LABEL));
    }
    public function productName($name, $product)
    {
        $isRestRequest = defined('REST_REQUEST') && REST_REQUEST;
        $isFrontendAjax = wp_doing_ajax() && isset($_REQUEST['wc-ajax']);
        if ((is_admin() && !$isRestRequest && !$isFrontendAjax) || !WC()->cart) {
            return $name;
        }

        $productId = (int) $product->get_id();
        foreach (WC()->cart->get_cart() as $cartItem) {
            $cartProductId = (int) (!empty($cartItem['variation_id']) ? $cartItem['variation_id'] : ($cartItem['product_id'] ?? 0));
            if (isset($cartItem['data']) && ($cartItem['data'] === $product || $cartProductId === $productId)) {
                $tier = $cartItem['alttag_pricing_tier'] ?? null;
                return $this->appendTierLabel($name, $tier['label'] ?? '');
            }
        }
        return $name;
    }
    public function storeApiOrderItemName($name, $item)
    {
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return $name;
        }
        return $this->appendTierLabel($name, $item->get_meta(self::ITEM_LABEL));
    }
    private function appendTierLabel($name, $label)
    {
        if ($label === '') {
            return $name;
        }
        $suffix = ' - '.esc_html($label);
        return substr($name, -strlen($suffix)) === $suffix ? $name : $name.$suffix;
    }
    public function orderData($d, $order)
    {
        foreach ($order->get_items() as $i) {
            $k = $i->get_meta(self::ITEM_KEY);
            if ($k !== '') {
                $d['pricing_tier_key'] = $k;
                $d['pricing_tier_label'] = $i->get_meta(self::ITEM_LABEL);
                $d['pricing_tier_price'] = $i->get_meta(self::ITEM_PRICE);
                break;
            }
        }return$d;
    }
    public function participantFields($f)
    {
        $f['pricing_tier_key'] = ['label' => __('Pricing tier key', 'alttag-registrations'),'type' => 'text','readonly' => true];
        $f['pricing_tier_label'] = ['label' => __('Pricing tier', 'alttag-registrations'),'type' => 'text','readonly' => true];
        $f['pricing_tier_price'] = ['label' => __('Tier price', 'alttag-registrations'),'type' => 'number','step' => '0.01','readonly' => true];
        return$f;
    }
    public function columns($c)
    {
        $o = [];
        foreach ($c as $k => $v) {
            $o[$k] = $v;
            if ($k === 'product_name') {
                $o['pricing_tier'] = apply_filters('alttag_registrations_pricing_tier_label', __('Pricing tier', 'alttag-registrations'));
            }
        }return$o;
    }
    public function column($content, $column, $id)
    {
        if ($column !== 'pricing_tier') {
            return$content;
        }$v = get_post_meta($id, 'pricing_tier_label', true);
        return$v !== '' ? esc_html($v) : '-';
    }
    public function filter()
    {
        global$wpdb;
        $rows = $wpdb->get_results("SELECT DISTINCT k.meta_value tier_key,l.meta_value tier_label FROM {$wpdb->postmeta} k LEFT JOIN {$wpdb->postmeta} l ON l.post_id=k.post_id AND l.meta_key='pricing_tier_label' WHERE k.meta_key='pricing_tier_key' AND k.meta_value<>''");
        $opts = apply_filters('alttag_registrations_pricing_tier_filter_options', []);
        foreach ($rows as $r) {
            $opts[$r->tier_key] = $r->tier_label ?: $r->tier_key;
        }if (!$opts) {
            return;
        }$cur = isset($_GET['participant_pricing_tier']) ? sanitize_key(wp_unslash($_GET['participant_pricing_tier'])) : '';
        echo '<select name="participant_pricing_tier"><option value="">'.esc_html__('All pricing tiers', 'alttag-registrations').'</option>';
        foreach ($opts as $k => $v) {
            echo '<option value="'.esc_attr($k).'" '.selected($cur, $k, false).'>'.esc_html($v).'</option>';
        }echo '</select>';
    }
    public function filterQuery($q)
    {
        if (!empty($_GET['participant_pricing_tier'])) {
            $q[] = ['key' => 'pricing_tier_key','value' => sanitize_key(wp_unslash($_GET['participant_pricing_tier'])),'compare' => '='];
        }return$q;
    }
    public function filterArgs($a)
    {
        $a[] = 'participant_pricing_tier';
        return array_values(array_unique($a));
    }
}
