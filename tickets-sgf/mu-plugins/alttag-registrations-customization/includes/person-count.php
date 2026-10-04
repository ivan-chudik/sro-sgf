<?php

namespace Alttag\Registrations\Customization;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Person count ("Osoby") for orders and WooCommerce Analytics.
 *
 * Products are tickets, so one order can register several people. Neither
 * `num_items_sold` nor the order total answers "how many people came" — a
 * multi-day ticket is several line items for one person, and the extras added
 * in MultiParticipantModule are separate people on a single line item.
 *
 * Source of truth is therefore the `participant` CPT: every person the order
 * registered (buyer + extras, MultiParticipantModule.php:626) carries an
 * `order_id` post meta pointing back at the order. Orders that never produce a
 * participant record (livestream products) fall back to the sum of item
 * quantities.
 *
 * The value is cached on the order as `_alttag_person_count` so the Analytics
 * table does not run a meta query per row, and re-synced whenever the order or
 * one of its participants changes.
 *
 * Surfaces (all fed from the same value):
 *   - REST `wc-analytics/reports/orders`, field `person_count`
 *     (register_rest_field on the `report_orders` object — GenericController
 *     runs add_additional_fields_to_object/…_schema, so it lands in both the
 *     payload and the schema).
 *   - Analytics > Orders table column, appended client side through the
 *     `woocommerce_admin_report_table` JS filter (assets/js/analytics-persons.js).
 *   - CSV export: the client-side download reuses the filtered table columns,
 *     the server-side (e-mailed) export uses
 *     `woocommerce_report_orders_export_columns` /
 *     `woocommerce_report_orders_prepare_export_item`
 *     (Reports/Orders/Controller.php:460,492).
 */

const PERSON_COUNT_META = '_alttag_person_count';

/**
 * Count the participant records attached to an order.
 *
 * Deliberately not ParticipantManager::getParticipantByOrderId() — that one
 * returns the buyer only (Participant/Manager.php:205) and would report 1 for
 * every multi-person order.
 */
function person_count_participants(int $order_id): int
{
    if ($order_id <= 0) {
        return 0;
    }

    $ids = get_posts([
        'post_type'      => 'participant',
        'post_status'    => 'any',
        'fields'         => 'ids',
        'numberposts'    => -1,
        'no_found_rows'  => true,
        'meta_key'       => 'order_id',
        'meta_value'     => (string) $order_id,
        // Participants are not translated; without this Polylang would scope
        // the query to the current admin language and undercount.
        'lang'           => '',
    ]);

    return count($ids);
}

/**
 * Fallback for orders with no participant records (livestream products).
 */
function person_count_quantity(\WC_Abstract_Order $order): int
{
    $quantity = 0;
    foreach ($order->get_items() as $item) {
        $quantity += (int) $item->get_quantity();
    }

    return $quantity;
}

/**
 * Persons an order stands for, recomputed from scratch.
 */
function person_count_compute(\WC_Abstract_Order $order): int
{
    $participants = person_count_participants((int) $order->get_id());

    return $participants > 0 ? $participants : person_count_quantity($order);
}

/**
 * Write the person count onto the order when it is missing or stale.
 *
 * Uses save_meta_data() rather than save(): this also runs from inside
 * `woocommerce_order_status_changed`, which WooCommerce fires during
 * WC_Order::save() — a nested full save would re-enter the CRUD write.
 *
 * @param \WC_Order|int $order
 * @return int|null New value when it changed, null when it was already correct.
 */
function person_count_sync($order): ?int
{
    if (!$order instanceof \WC_Order) {
        $order = wc_get_order($order);
    }
    if (!$order instanceof \WC_Order) {
        return null;
    }

    $value  = person_count_compute($order);
    $stored = $order->get_meta(PERSON_COUNT_META, true);

    if ($stored !== '' && (int) $stored === $value) {
        return null;
    }

    $order->update_meta_data(PERSON_COUNT_META, $value);
    $order->save_meta_data();

    return $value;
}

/**
 * Value for the report row: the cached meta, computed live when never synced
 * so a fresh install shows real numbers before the backfill is run.
 */
function person_count_for_report(int $order_id): int
{
    if ($order_id <= 0) {
        return 0;
    }

    $order = wc_get_order($order_id);

    // Refunds are their own rows in Analytics > Orders and report negative
    // `num_items_sold`. Mirror that so the column nets out the same way the
    // rest of the table does: a refunded ticket removes its person again.
    if ($order instanceof \WC_Order_Refund) {
        // Refund line items already carry a negative quantity.
        return person_count_quantity($order);
    }

    if (!$order instanceof \WC_Order) {
        return 0;
    }

    $stored = $order->get_meta(PERSON_COUNT_META, true);

    return $stored !== '' ? (int) $stored : person_count_compute($order);
}

// ------------------------------------------------------------------ sync

// Participants are created on `woocommerce_order_status_changed`
// (WooCommerceManager prio 10, MultiParticipantModule extras prio 20), which
// runs inside the save triggered by payment_complete(). Priority 99 on both
// hooks means the records already exist when we count them.
add_action('woocommerce_payment_complete', __NAMESPACE__ . '\\person_count_sync', 99);
add_action('woocommerce_order_status_changed', function ($order_id, $old_status, $new_status, $order = null) {
    person_count_sync($order instanceof \WC_Order ? $order : $order_id);
}, 99, 4);

// Manual creation / editing of an order in wp-admin.
add_action('woocommerce_process_shop_order_meta', __NAMESPACE__ . '\\person_count_sync', 99);

// Participants are the source of truth, so adding or removing one has to move
// the number on the order it belongs to.
add_action('save_post_participant', function ($post_id) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    $order_id = (int) get_post_meta($post_id, 'order_id', true);
    if ($order_id > 0) {
        person_count_sync($order_id);
    }
}, 99);

// `deleted_post` fires after the post meta is gone, so remember the order first.
add_action('before_delete_post', function ($post_id) {
    if (get_post_type($post_id) !== 'participant') {
        return;
    }
    person_count_pending_order((int) $post_id, (int) get_post_meta($post_id, 'order_id', true));
}, 10);

add_action('deleted_post', function ($post_id) {
    $order_id = person_count_pending_order((int) $post_id);
    if ($order_id > 0) {
        person_count_sync($order_id);
    }
}, 10);

/**
 * Tiny store carrying an order ID between before_delete_post and deleted_post.
 *
 * @param int      $post_id
 * @param int|null $order_id Pass to store, omit to read and forget.
 */
function person_count_pending_order(int $post_id, ?int $order_id = null): int
{
    static $pending = [];

    if ($order_id !== null) {
        $pending[$post_id] = $order_id;
        return $order_id;
    }

    $stored = $pending[$post_id] ?? 0;
    unset($pending[$post_id]);

    return $stored;
}

// ------------------------------------------------------------ report surface

add_action('rest_api_init', function () {
    // `report_orders` is the schema title of Reports\Orders\Controller, which
    // WP_REST_Controller::get_object_type() uses to resolve additional fields.
    register_rest_field('report_orders', 'person_count', [
        'get_callback' => function ($report) {
            return person_count_for_report((int) ($report['order_id'] ?? 0));
        },
        'schema'       => [
            'description' => __('Number of persons registered by the order.', 'alttag-registrations-customization'),
            'type'        => 'integer',
            'context'     => ['view', 'edit'],
            'readonly'    => true,
        ],
    ]);
});

add_filter('woocommerce_report_orders_export_columns', function ($columns) {
    $columns['person_count'] = __('Persons', 'alttag-registrations-customization');

    return $columns;
});

add_filter('woocommerce_report_orders_prepare_export_item', function ($export_item, $item) {
    $export_item['person_count'] = person_count_for_report((int) ($item['order_id'] ?? 0));

    return $export_item;
}, 10, 2);

/**
 * The Analytics table columns are built in JS
 * (assets/client/admin/chunks/analytics-report-orders.js), so the column is
 * appended through the `woocommerce_admin_report_table` filter that
 * ReportTable applies to every report.
 */
add_action('admin_enqueue_scripts', function () {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
    if (!isset($_GET['page']) || $_GET['page'] !== 'wc-admin') {
        return;
    }

    wp_enqueue_script(
        'alttag-analytics-persons',
        ALTTAG_REGISTRATIONS_CUSTOMIZATION_URL . 'assets/js/analytics-persons.js',
        ['wp-hooks'],
        '1.0.0',
        true
    );

    // The label is translated in PHP: this plugin ships .po/.l10n.php only, it
    // has no wp_set_script_translations JSON catalogue.
    wp_localize_script('alttag-analytics-persons', 'alttagPersonColumn', [
        'label' => __('Persons', 'alttag-registrations-customization'),
    ]);
});
