<?php

namespace Alttag\Registrations\Customization;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Person counts" maintenance screen.
 *
 * The hooks in person-count.php only stamp `_alttag_person_count` on orders
 * that change from now on; the orders already in the shop need one pass. The
 * screen follows GroupSeatingUI's overview page (GroupSeatingUI.php:227): a
 * submenu under Participants with a nonce-protected page-title-action, so the
 * recalculation is a normal admin GET and needs no extra AJAX plumbing.
 *
 * person_count_sync() only writes when the stored value differs, so a second
 * run reports 0 changed orders.
 */

const PERSON_COUNT_PAGE_SLUG = 'alttag-person-counts';
const PERSON_COUNT_RECALC_ACTION = 'alttag_person_count_recalc';

add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=participant',
        __('Person counts', 'alttag-registrations-customization'),
        __('Person counts', 'alttag-registrations-customization'),
        'manage_woocommerce',
        PERSON_COUNT_PAGE_SLUG,
        __NAMESPACE__ . '\\person_count_render_page'
    );
}, 99);

/**
 * Recalculate every order, in pages so a few thousand orders do not have to be
 * hydrated at once.
 *
 * @return array{scanned:int,changed:int}
 */
function person_count_backfill(): array
{
    // One unpaginated ID query: paging wc_get_orders() with an offset can skip
    // rows when the sort is not stable, and a list of IDs is cheap even for a
    // shop many times this size. Orders are hydrated one chunk at a time.
    $order_ids = wc_get_orders([
        'limit'   => -1,
        'status'  => array_keys(wc_get_order_statuses()),
        'type'    => 'shop_order',
        'return'  => 'ids',
        'orderby' => 'id',
        'order'   => 'ASC',
        // Polylang joins term_relationships onto the HPOS orders query and
        // scopes it to the current language: without this the backfill sees
        // only the Slovak orders (526 of 767 at the time of writing).
        'lang'    => '',
    ]);

    $scanned = 0;
    $changed = 0;

    foreach (array_chunk($order_ids, 100) as $chunk) {
        foreach ($chunk as $order_id) {
            ++$scanned;
            if (person_count_sync((int) $order_id) !== null) {
                ++$changed;
            }
        }
        // Long backfills otherwise grow the order cache until PHP runs out of
        // memory; the loop never revisits an order.
        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }
    }

    return ['scanned' => $scanned, 'changed' => $changed];
}

/**
 * Handle the recalculation request. Returns the notice markup to print.
 */
function person_count_maybe_recalculate(): string
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below.
    if (($_GET['action'] ?? '') !== PERSON_COUNT_RECALC_ACTION) {
        return '';
    }

    if (!current_user_can('manage_woocommerce')) {
        return '';
    }

    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
    if (!wp_verify_nonce($nonce, PERSON_COUNT_RECALC_ACTION)) {
        return '';
    }

    $result = person_count_backfill();

    return sprintf(
        '<div class="notice notice-success"><p>%s</p></div>',
        esc_html(sprintf(
            /* translators: 1: number of orders updated, 2: number of orders checked */
            __('Person count recalculated: %1$d of %2$d orders updated.', 'alttag-registrations-customization'),
            $result['changed'],
            $result['scanned']
        ))
    );
}

function person_count_render_page(): void
{
    // Run before anything is printed so the notice sits under the heading.
    $notice = person_count_maybe_recalculate();

    echo '<div class="wrap">';
    echo '<h1 class="wp-heading-inline">'
        . esc_html__('Person counts', 'alttag-registrations-customization') . '</h1>';

    printf(
        ' <a href="%s" class="page-title-action">%s</a>',
        esc_url(wp_nonce_url(
            add_query_arg(
                [
                    'post_type' => 'participant',
                    'page'      => PERSON_COUNT_PAGE_SLUG,
                    'action'    => PERSON_COUNT_RECALC_ACTION,
                ],
                admin_url('edit.php')
            ),
            PERSON_COUNT_RECALC_ACTION
        )),
        esc_html__('Recalculate person counts', 'alttag-registrations-customization')
    );

    echo '<hr class="wp-header-end">';
    echo $notice; // phpcs:ignore WordPress.Security.EscapingOutput -- built above from escaped parts.

    echo '<p>' . esc_html__(
        'Stores the number of people each order registers, shown as the Persons column in Analytics > Orders and included in its CSV export. The number comes from the participant records linked to the order; orders without participants (livestream) fall back to the ordered quantity. Orders are kept up to date automatically — run this after importing participants or to fill in older orders.',
        'alttag-registrations-customization'
    ) . '</p>';

    echo '</div>';
}
