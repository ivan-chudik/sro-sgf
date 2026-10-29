<?php
/**
 * Plugin Name: WooCommerce Order Thank You Page Link
 * Description: Adds a link to the thank you page in the WooCommerce order admin
 * Version: 1.0.0
 * Author: Alttag
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Order_Thankyou_Link
{
    public function __construct()
    {
        // Add meta box to order edit page
        add_action('add_meta_boxes', [$this, 'addMetaBox']);

        // Add column to orders list (HPOS compatible)
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'addOrdersColumn']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'renderOrdersColumn'], 10, 2);

        // Legacy support for non-HPOS
        add_filter('manage_edit-shop_order_columns', [$this, 'addOrdersColumn']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'renderOrdersColumnLegacy'], 10, 2);
    }

    /**
     * Add meta box to order edit page
     */
    public function addMetaBox()
    {
        $screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')
            && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'wc_order_thankyou_link',
            __('Thank You Page', 'wc-order-thankyou-link'),
            [$this, 'renderMetaBox'],
            $screen,
            'side',
            'default'
        );
    }

    /**
     * Render meta box content
     *
     * @param WP_Post|WC_Order $post_or_order
     */
    public function renderMetaBox($post_or_order)
    {
        $order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);

        if (!$order) {
            echo '<p>' . esc_html__('Order not found.', 'wc-order-thankyou-link') . '</p>';
            return;
        }

        $thankyou_url = $this->getThankYouUrl($order);
        ?>
        <p>
            <a href="<?php echo esc_url($thankyou_url); ?>" target="_blank" class="button button-secondary">
                <?php esc_html_e('Open Thank You Page', 'wc-order-thankyou-link'); ?>
            </a>
        </p>
        <p class="description">
            <code style="word-break: break-all; font-size: 11px;"><?php echo esc_html($thankyou_url); ?></code>
        </p>
        <?php
    }

    /**
     * Add column to orders list
     *
     * @param array $columns
     * @return array
     */
    public function addOrdersColumn($columns)
    {
        $new_columns = [];

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;

            // Add after order status column
            if ($key === 'order_status') {
                $new_columns['thankyou_link'] = __('Thank You Link', 'wc-order-thankyou-link');
            }
        }

        return $new_columns;
    }

    /**
     * Render column content (HPOS)
     *
     * @param string $column
     * @param WC_Order $order
     */
    public function renderOrdersColumn($column, $order)
    {
        if ($column !== 'thankyou_link') {
            return;
        }

        $thankyou_url = $this->getThankYouUrl($order);
        echo '<a href="' . esc_url($thankyou_url) . '" target="_blank" title="' . esc_attr__('Open Thank You Page', 'wc-order-thankyou-link') . '">&#128279;</a>';
    }

    /**
     * Render column content (Legacy)
     *
     * @param string $column
     * @param int $post_id
     */
    public function renderOrdersColumnLegacy($column, $post_id)
    {
        if ($column !== 'thankyou_link') {
            return;
        }

        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }

        $thankyou_url = $this->getThankYouUrl($order);
        echo '<a href="' . esc_url($thankyou_url) . '" target="_blank" title="' . esc_attr__('Open Thank You Page', 'wc-order-thankyou-link') . '">&#128279;</a>';
    }

    /**
     * Get thank you page URL for an order
     *
     * @param WC_Order $order
     * @return string
     */
    private function getThankYouUrl($order)
    {
        return $order->get_checkout_order_received_url();
    }
}

new WC_Order_Thankyou_Link();
