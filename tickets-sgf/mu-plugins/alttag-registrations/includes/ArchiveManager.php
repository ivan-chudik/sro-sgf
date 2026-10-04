<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Non-destructive "Archív" status for participants, products and orders.
 *
 * Why this exists
 * ---------------
 * Trash is destructive (auto-deleted after 30 days) and the WC list table
 * collapses status counts; admins wanted a "set aside but keep forever"
 * lifecycle stage. Archived records stay queryable, get their own list-table
 * tab, and are excluded from front-end queries.
 *
 * Coverage
 * --------
 *   - participant
 *   - product
 *   - shop_order  (needs the `wc-` prefixed status `wc-archived`: WC writes
 *                  the order status column through
 *                  `Abstract_WC_Order_Data_Store_CPT::get_post_status()`,
 *                  which only prefixes a status when `wc-<status>` is a
 *                  REGISTERED post status. Registering bare `archived` for
 *                  `shop_order` made HPOS store the raw value `archived`,
 *                  while `OrdersTableQuery::sanitize_status()` normalises the
 *                  query to `wc-archived` — so nothing was ever found.)
 *
 * Unarchive target
 * ----------------
 * Restoring from archive returns the record to a sensible default:
 *   - participant → publish
 *   - product     → draft   (so it doesn't reappear in the catalog
 *                           unintentionally)
 *   - shop_order  → wc-processing
 *
 * Adjustable via the {@see ArchiveManager::UNARCHIVE_TARGETS} map.
 */
class ArchiveManager
{
    /** Archived status for regular post types (participant, product). */
    const STATUS = 'archived';

    /**
     * Archived status for orders. Orders are stored with a `wc-` prefix both
     * in `wp_posts.post_status` (classic) and in `wc_orders.status` (HPOS),
     * and WC only applies that prefix when the prefixed name is a registered
     * post status.
     */
    const ORDER_STATUS = 'wc-archived';

    const POST_TYPES = ['participant', 'product', 'shop_order'];

    /** Post types that use the unprefixed {@see ArchiveManager::STATUS}. */
    const LEGACY_POST_TYPES = ['participant', 'product'];

    const UNARCHIVE_TARGETS = [
        'participant' => 'publish',
        'product'     => 'draft',
        'shop_order'  => 'wc-processing',
    ];

    public function registerHooks()
    {
        add_action('init', [$this, 'registerStatus'], 11);

        // Legacy list tables: participant, product (always), shop_order (only
        // when HPOS is off — when HPOS is on this screen is hidden)
        foreach (self::POST_TYPES as $post_type) {
            add_filter("bulk_actions-edit-{$post_type}", [$this, 'addBulkActions']);
            add_filter("handle_bulk_actions-edit-{$post_type}", [$this, 'handleBulkAction'], 10, 3);
        }
        foreach (['participant', 'product'] as $post_type) {
            add_filter("views_edit-{$post_type}", [$this, 'addStatusView']);
        }

        // HPOS Orders list (screen id "woocommerce_page_wc-orders") —
        // WC's custom list table uses its own filter names. Always register;
        // hooks are inert when HPOS is off.
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'addBulkActions']);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handleBulkAction'], 10, 3);
        // Make `wc-archived` a known WC order status so update_status('archived') works
        add_filter('wc_order_statuses', [$this, 'addWcOrderStatus']);
        // Polylang-WC scopes every HPOS order query to the current admin
        // language (`woocommerce_orders_table_query_clauses`). The archive is
        // a site-wide lifecycle stage, so the archived view must list orders
        // in every language — `lang => ''` disables that scoping.
        add_filter(
            'woocommerce_order_list_table_prepare_items_query_args',
            [$this, 'unscopeArchivedOrderQueryLanguage']
        );

        add_filter('post_row_actions', [$this, 'addRowAction'], 10, 2);
        add_filter('page_row_actions', [$this, 'addRowAction'], 10, 2);
        add_action('admin_action_alttag_toggle_archive', [$this, 'handleRowAction']);
        add_action('admin_notices', [$this, 'maybeShowAdminNotice']);

        add_action('pre_get_posts', [$this, 'allowArchivedQuery']);

        // Exclude archived products from front-end catalog
        add_action('woocommerce_product_query', [$this, 'excludeArchivedFromShop']);
    }

    /**
     * Register the archived status with WooCommerce so `WC_Order::update_status`
     * accepts it and the order is listed under that status in the admin.
     */
    public function addWcOrderStatus($statuses)
    {
        $statuses['wc-archived'] = _x('Archived', 'order status', 'alttag-registrations');
        return $statuses;
    }

    public function registerStatus()
    {
        register_post_status(self::STATUS, [
            'label' => _x('Archived', 'post status', 'alttag-registrations'),
            'label_count' => _n_noop(
                'Archived <span class="count">(%s)</span>',
                'Archived <span class="count">(%s)</span>',
                'alttag-registrations'
            ),
            'public' => false,
            'internal' => true,
            'protected' => true,
            'private' => false,
            'exclude_from_search' => true,
            'show_in_admin_all_list' => false,
            'show_in_admin_status_list' => true,
            'post_type' => self::LEGACY_POST_TYPES,
        ]);

        // Orders: the prefixed twin. Registering it makes WC (a) persist the
        // status as `wc-archived` instead of a raw `archived`, (b) render the
        // "Archived" tab on the HPOS orders screen — `ListTable::get_views()`
        // intersects `wc_get_order_statuses()` with the post stati flagged
        // `show_in_admin_status_list` — and (c) keep archived orders out of
        // the All view, which is built from `show_in_admin_all_list` stati.
        // Argument shape mirrors WC's own order statuses (no `internal` /
        // `protected`) so WC treats it like any other order status.
        register_post_status(self::ORDER_STATUS, [
            'label' => _x('Archived', 'order status', 'alttag-registrations'),
            'label_count' => _n_noop(
                'Archived <span class="count">(%s)</span>',
                'Archived <span class="count">(%s)</span>',
                'alttag-registrations'
            ),
            'public' => false,
            'exclude_from_search' => true,
            'show_in_admin_all_list' => false,
            'show_in_admin_status_list' => true,
            'post_type' => ['shop_order'],
        ]);
    }

    /**
     * Archived status name for a post type.
     */
    private function archivedStatus($post_type)
    {
        return $post_type === 'shop_order' ? self::ORDER_STATUS : self::STATUS;
    }

    /**
     * Drop the Polylang-WC language scope while the archived view is shown so
     * the list matches the (language-agnostic) count WooCommerce prints.
     */
    public function unscopeArchivedOrderQueryLanguage($query_args)
    {
        $statuses = isset($query_args['status']) ? (array) $query_args['status'] : [];
        if (in_array(self::ORDER_STATUS, $statuses, true)) {
            $query_args['lang'] = '';
        }
        return $query_args;
    }

    public function addBulkActions($actions)
    {
        $actions['alttag_archive']   = __('Move to archive', 'alttag-registrations');
        $actions['alttag_unarchive'] = __('Restore from archive', 'alttag-registrations');
        return $actions;
    }

    public function handleBulkAction($redirect, $action, $ids)
    {
        if (!in_array($action, ['alttag_archive', 'alttag_unarchive'], true)) {
            return $redirect;
        }

        $post_type = $this->currentScreenPostType();

        $target = $action === 'alttag_archive'
            ? $this->archivedStatus($post_type)
            : ($this->unarchiveTarget($post_type));

        $count = 0;
        foreach ((array) $ids as $id) {
            if ($this->applyStatus((int) $id, $target)) {
                $count++;
            }
        }

        return add_query_arg([
            'alttag_archive_count' => $count,
            'alttag_archive_action' => $action,
        ], $redirect);
    }

    public function addRowAction($actions, $post)
    {
        if (!in_array($post->post_type, self::POST_TYPES, true)) {
            return $actions;
        }
        if (!current_user_can('edit_post', $post->ID)) {
            return $actions;
        }

        $is_archived = $post->post_status === $this->archivedStatus($post->post_type);
        $target = $is_archived
            ? $this->unarchiveTarget($post->post_type)
            : self::STATUS;

        $url = wp_nonce_url(
            add_query_arg([
                'action'            => 'alttag_toggle_archive',
                'post'              => $post->ID,
                'alttag_archive_to' => $target,
            ], admin_url('post.php')),
            'alttag_archive_' . $post->ID
        );

        $label = $is_archived
            ? __('Restore from archive', 'alttag-registrations')
            : __('Move to archive', 'alttag-registrations');

        $actions['alttag_archive'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($url),
            esc_html($label)
        );

        return $actions;
    }

    public function handleRowAction()
    {
        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        $target = isset($_GET['alttag_archive_to']) ? sanitize_text_field($_GET['alttag_archive_to']) : '';

        if (!$post_id || !$target) {
            wp_die(esc_html__('Missing parameters', 'alttag-registrations'));
        }
        check_admin_referer('alttag_archive_' . $post_id);
        if (!current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('Forbidden', 'alttag-registrations'));
        }

        $this->applyStatus($post_id, $target);

        $referer = wp_get_referer();
        if (!$referer) {
            $post = get_post($post_id);
            $referer = $post ? admin_url('edit.php?post_type=' . $post->post_type) : admin_url();
        }
        wp_safe_redirect($referer);
        exit;
    }

    public function maybeShowAdminNotice()
    {
        if (!isset($_GET['alttag_archive_count'], $_GET['alttag_archive_action'])) {
            return;
        }
        $count = (int) $_GET['alttag_archive_count'];
        $action = sanitize_text_field($_GET['alttag_archive_action']);

        $message = $action === 'alttag_archive'
            ? sprintf(
                /* translators: %d: number of records moved to archive */
                _n('%d record moved to archive.', '%d records moved to archive.', $count, 'alttag-registrations'),
                $count
            )
            : sprintf(
                /* translators: %d: number of records restored from archive */
                _n(
                    '%d record restored from archive.',
                    '%d records restored from archive.',
                    $count,
                    'alttag-registrations'
                ),
                $count
            );

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html($message)
        );
    }

    public function addStatusView($views)
    {
        $post_type = $this->currentScreenPostType();

        // Orders: WooCommerce's own `ListTable::get_views()` now renders the
        // Archived tab (and its count, a language-agnostic `COUNT(*)` over
        // `wc_orders`) because `wc-archived` is a registered post status.
        // Adding a second tab here would duplicate it.
        if (!in_array($post_type, self::LEGACY_POST_TYPES, true)) {
            return $views;
        }

        $counts = wp_count_posts($post_type);
        $archived = isset($counts->{self::STATUS}) ? (int) $counts->{self::STATUS} : 0;

        $is_current_view = isset($_GET['post_status']) && $_GET['post_status'] === self::STATUS;

        if ($archived <= 0 && !$is_current_view) {
            return $views;
        }

        $current_class = $is_current_view ? ' class="current"' : '';
        $url = add_query_arg(
            ['post_type' => $post_type, 'post_status' => self::STATUS],
            admin_url('edit.php')
        );

        $views['archived'] = sprintf(
            '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
            esc_url($url),
            $current_class,
            esc_html__('Archived', 'alttag-registrations'),
            $archived
        );

        return $views;
    }

    public function allowArchivedQuery($q)
    {
        if (!is_admin() || !$q->is_main_query()) {
            return;
        }
        $post_type = $q->get('post_type');
        if (!in_array($post_type, self::POST_TYPES, true)) {
            return;
        }
        if (!isset($_GET['post_status']) || $_GET['post_status'] !== self::STATUS) {
            return;
        }
        $q->set('post_status', self::STATUS);
    }

    public function excludeArchivedFromShop($q)
    {
        if (is_admin()) {
            return;
        }
        $statuses = (array) $q->get('post_status');
        $statuses = $statuses ?: ['publish'];
        $q->set('post_status', array_values(array_diff($statuses, [self::STATUS])));
    }

    /**
     * Apply a status transition. Uses WC's setter for orders so HPOS and
     * legacy storage are both kept in sync.
     */
    private function applyStatus($post_id, $target_status)
    {
        if (!$post_id) {
            return false;
        }
        // Under HPOS there is no `wp_posts` row for an order, so the post
        // type has to be resolved through WooCommerce first.
        $post_type = $this->resolvePostType($post_id);

        if ($post_type === 'shop_order') {
            $order = wc_get_order($post_id);
            if (!$order instanceof \WC_Order) {
                return false;
            }
            $status = strpos($target_status, 'wc-') === 0
                ? substr($target_status, 3)
                : $target_status;
            $order->update_status($status, __('Manual archive action', 'alttag-registrations'));
            return true;
        }

        if (!$post_type) {
            return false;
        }

        return (bool) wp_update_post([
            'ID' => $post_id,
            'post_status' => $target_status,
        ]);
    }

    /**
     * Post type of a record id, HPOS-safe.
     */
    private function resolvePostType($post_id)
    {
        if (class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil')) {
            $order_type = \Automattic\WooCommerce\Utilities\OrderUtil::get_order_type($post_id);
            if ($order_type) {
                return $order_type;
            }
        }
        $post = get_post($post_id);
        return $post ? $post->post_type : '';
    }

    /**
     * Effective post type of the current admin screen. The HPOS orders list
     * lives on `woocommerce_page_wc-orders`, where `$screen->post_type` is
     * empty, so it has to be derived from the screen id.
     */
    private function currentScreenPostType()
    {
        $screen = get_current_screen();
        if (!$screen) {
            return '';
        }
        if ($screen->id === 'woocommerce_page_wc-orders') {
            return 'shop_order';
        }
        return (string) $screen->post_type;
    }

    private function unarchiveTarget($post_type)
    {
        return self::UNARCHIVE_TARGETS[$post_type] ?? 'publish';
    }
}
