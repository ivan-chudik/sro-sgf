<?php

namespace Alttag\Registrations\Participant;

use Alttag\Registrations\LivestreamManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles admin UI for participants
 */
class AdminUI
{
    private $manager;
    private $ticket;
    private $email;
    
    public function __construct(Manager $manager, Ticket $ticket, Email $email)
    {
        $enable = apply_filters('alttag_registrations_enable_participants', true);

        if (!$enable) {
            return;
        }

        $this->manager = $manager;
        $this->ticket = $ticket;
        $this->email = $email;
        
        // Add columns to admin list
        add_filter('manage_participant_posts_columns', [$this, 'setParticipantColumns']);
        add_action('manage_participant_posts_custom_column', [$this, 'renderParticipantColumn'], 10, 2);
        add_filter('manage_edit-participant_sortable_columns', [$this, 'setSortableColumns']);

        // Add filters
        add_action('restrict_manage_posts', [$this, 'addParticipantFilters']);
        add_filter('parse_query', [$this, 'filterParticipantQuery']);

        // Add export button to the participants list page
        add_action('manage_posts_extra_tablenav', [$this, 'addExportAllButton']);

        // Add meta box for resending email with ticket and invoice
        add_action('add_meta_boxes', [$this, 'addResendEmailWithTicketAndInvoiceMetaBox']);

        // Add meta box for regenerating ticket
        add_action('add_meta_boxes', [$this, 'addRegenerateTicketMetaBox']);

        // Add meta box for admin previews (email / thank-you / Stripe receipt)
        add_action('add_meta_boxes', [$this, 'addPreviewsMetaBox']);

        // Add meta box for reimporting participant into Riverstream
        add_action('add_meta_boxes', [$this, 'addReimportRiverstreamMetaBox']);
        add_action('admin_post_reimport_riverstream', [$this, 'handleReimportRiverstream']);

        // Add admin notices for ticket regeneration and email resending
        add_action('admin_notices', [$this, 'displayAdminNotices']);

        // Add bulk actions
        add_filter('bulk_actions-edit-participant', [$this, 'registerBulkActions']);
        add_filter('handle_bulk_actions-edit-participant', [$this, 'handleBulkActions'], 10, 3);

        // Column widths on participant list
        add_action('admin_head', [$this, 'printListTableStyles']);
    }

    public function printListTableStyles()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-participant') {
            return;
        }
        ?>
        <style>
            /* alttag-filter-bar */
            .wp-list-table .column-language { width: 70px; white-space: nowrap; }

            /* The filter bar. Core lays this out with floats and assumes two or
               three filters; this screen has ten, so it is a wrapping flex row
               instead. */
            .post-type-participant .tablenav.top {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 8px;
                height: auto;
                margin-bottom: 12px;
            }

            .post-type-participant .tablenav.top::after {
                content: none;
            }

            .post-type-participant .tablenav.top > * {
                float: none;
                margin: 0;
            }

            /* Core also puts a clearing <br> in here (and hooks can add hidden
               inputs). In a flex container those count as items, so the gap is
               applied around them too and the first visible block ends up pushed
               in by 8px. Out of the flow they go. */
            .post-type-participant .tablenav.top > br,
            .post-type-participant .tablenav.top > input[type="hidden"] {
                display: none;
            }

            .post-type-participant .tablenav.top .actions {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 6px;
                padding: 0;
            }

            /* Row one: bulk actions on the left, the export buttons pushed to the
               right. The filters take the whole next row. */
            .post-type-participant .tablenav.top .bulkactions {
                flex: 0 0 auto;
                order: 1;
            }


            .post-type-participant .tablenav.top .alttag-export-actions {
                display: flex;
                flex: 0 0 auto;
                flex-wrap: wrap;
                gap: 8px;
                order: 2;
                margin-left: auto;
            }

            .post-type-participant .tablenav.top .actions:not(.bulkactions) {
                order: 3;
            }

            .post-type-participant .tablenav.top .tablenav-pages {
                order: 4;
            }

            .post-type-participant .tablenav.top .actions:not(.bulkactions) {
                flex: 1 1 100%;
            }

            /* A long product name must not stretch the whole row. */
            .post-type-participant .tablenav.top .actions select {
                max-width: 200px;
            }

            .post-type-participant .tablenav.top .actions label,
            .post-type-participant .tablenav.top .actions .alttag-filter-label {
                white-space: nowrap;
            }

            .post-type-participant .tablenav.top .actions input[type="date"],
            .post-type-participant .tablenav.top .actions input[type="text"] {
                max-width: 150px;
            }

            .post-type-participant .tablenav.top .tablenav-pages {
                flex: 1 1 auto;
                margin: 0;
                text-align: right;
            }

            @media screen and (max-width: 782px) {
                .post-type-participant .tablenav.top .actions select,
                .post-type-participant .tablenav.top .actions input[type="date"],
                .post-type-participant .tablenav.top .actions input[type="text"] {
                    max-width: none;
                    flex: 1 1 100%;
                }
            }
        </style>
        <?php
    }
    
    /**
     * Set participant columns
     *
     * @param array $columns The columns
     * 
     * @return array The modified columns
     */
    public function setParticipantColumns($columns)
    {
        $new_columns = [
            'cb' => $columns['cb'],
            'first_name' => __('First Name', 'alttag-registrations'),
            'last_name' => __('Last Name', 'alttag-registrations'),
            'email' => __('Email', 'alttag-registrations'),
            'phone' => __('Phone', 'alttag-registrations'),
            'product_name' => __('Product', 'alttag-registrations'),
            'registration_status' => __('Registration Status', 'alttag-registrations'),
            'create_date' => __('Create Date', 'alttag-registrations'),
            'actions' => __('Actions', 'alttag-registrations'),
        ];

        // Payment column shows only when at least one participant in the
        // current admin view has an unpaid underlying order. When every
        // order is already paid, the column adds no signal and gets hidden
        // to keep the table compact.
        if ($this->hasAnyUnpaidParticipantInCurrentView()) {
            $actions = $new_columns['actions'];
            unset($new_columns['actions']);
            $new_columns['payment_status'] = __('Payment', 'alttag-registrations');
            $new_columns['actions'] = $actions;
        }

        $ctx = \Alttag\Registrations\RegistrationContext::current();

        // Add language column if multilingual
        if ($ctx->isMultilingual()) {
            $actions = $new_columns['actions'];
            unset($new_columns['actions']);
            $new_columns['language'] = __('Lang', 'alttag-registrations');
            $new_columns['actions'] = $actions;
        }

        // Add livestream access column if livestream is enabled
        if ($ctx->isLivestreamEnabled()) {
            // Insert livestream columns before actions
            $actions = $new_columns['actions'];
            unset($new_columns['actions']);
            $new_columns['is_livestream_user'] = __('Livestream User', 'alttag-registrations');
            $new_columns['livestream_access'] = __('Livestream Access', 'alttag-registrations');
            $new_columns['actions'] = $actions;
        }

        $new_columns = apply_filters('alttag_registrations_participant_columns', $new_columns);

        return $new_columns;
    }
    
    /**
     * Is there at least one participant in the current admin view whose
     * underlying WooCommerce order counts as unpaid?
     *
     * Payment "unpaid" mirrors the render logic in the `payment_status`
     * column case below:
     *   - order status `completed`         → paid
     *   - order status `processing` + card → paid
     *   - order status `processing` + bank → UNPAID
     *   - anything else                    → UNPAID
     *
     * Result is memoised per-request; the caller is `setParticipantColumns`
     * which the admin list-table can call more than once during a single
     * render (screen-options / hidden-columns machinery).
     *
     * @return bool
     */
    private function hasAnyUnpaidParticipantInCurrentView()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        global $wpdb;

        $status = isset($_GET['post_status']) ? sanitize_text_field((string) $_GET['post_status']) : '';
        if ($status !== '') {
            $status_sql = $wpdb->prepare(' AND p.post_status = %s', $status);
        } else {
            $status_sql = " AND p.post_status NOT IN ('archived', 'trash', 'auto-draft')";
        }

        $order_ids = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'participant'
               AND pm.meta_key = 'order_id'
               AND pm.meta_value <> ''
             $status_sql"
        );

        if (empty($order_ids)) {
            return $cache = false;
        }

        foreach ($order_ids as $order_id) {
            $order = wc_get_order((int) $order_id);
            if (!$order instanceof \WC_Order) {
                continue;
            }
            $wc_status = $order->get_status();
            if ($wc_status === 'completed') {
                continue;
            }
            $method = $order->get_payment_method();
            $is_bank_transfer = ($method === 'invoice_payment')
                || (bool) apply_filters('alttag_registrations_is_bank_transfer_gateway', false, $method, $order);
            if ($wc_status === 'processing' && !$is_bank_transfer) {
                continue;
            }
            return $cache = true;
        }

        return $cache = false;
    }

    /**
     * Render participant column
     *
     * @param string $column The column name
     * @param int $post_id The post ID
     *
     * @return void
     */
    public function renderParticipantColumn($column, $post_id)
    {
        // Allow filtering the column content before default handling
        $column_content = apply_filters('alttag_registrations_participant_column_content', null, $column, $post_id);

        if ($column_content !== null) {
            echo $column_content;
            return;
        }

        $metaFields = $this->manager->getMetaFields();
        $state = \Alttag\Registrations\ParticipantState::get($post_id);
        $ctx = \Alttag\Registrations\RegistrationContext::current();

        switch ($column) {
            case 'first_name':
                echo esc_html($state ? $state->first_name : '');
                break;
            case 'last_name':
                echo esc_html($state ? $state->last_name : '');
                break;
            case 'email':
                echo esc_html($state ? $state->email : '');
                break;
            case 'phone':
                echo esc_html($state ? $state->phone : '');
                break;
            case 'product_name':
                $product_name = $state ? $state->getMeta('product_name') : '';
                if (!$product_name && $state) {
                    $pid = (int) $state->getMeta('product_id');
                    if ($pid) {
                        $product_name = get_the_title($pid);
                    }
                }
                echo $product_name ? esc_html($product_name) : '—';
                break;
            case 'order_status':
                $order = $state ? $state->order() : null;
                echo $order ? esc_html($order->get_status()) : '';
                break;
            case 'registration_status':
                $value = $state ? $state->registrationStatus() : '';
                $options = $metaFields[$column]['options'] ?? [];
                echo isset($options[$value]) ? esc_html($options[$value]) : '-';
                break;
            case 'payment_status':
                // Whether the underlying WC order is actually paid. Two-axis
                // decision: WC order status × payment method.
                //   - completed              → PAID (always, no ambiguity)
                //   - processing + card      → PAID (Stripe captures on auth,
                //                              WC transitions immediately)
                //   - processing + bank      → UNPAID (invoice_payment gateway
                //                              flips to processing at checkout
                //                              but money hasn't arrived yet)
                //   - anything else          → UNPAID (pending / on-hold /
                //                              cancelled / refunded / failed)
                //
                // The `alttag_registrations_participant_payment_is_paid` filter
                // lets project-specific plugins override this heuristic (e.g.
                // if a custom gateway sets a different post-checkout status).
                $order = $state ? $state->order() : null;
                if (!$order instanceof \WC_Order) {
                    echo '<span style="color:#999;">—</span>';
                    break;
                }
                $status = $order->get_status();
                $method = $order->get_payment_method();
                $is_bank_transfer = ($method === 'invoice_payment')
                    || (bool) apply_filters('alttag_registrations_is_bank_transfer_gateway', false, $method, $order);
                if ($status === 'completed') {
                    $is_paid = true;
                } elseif ($status === 'processing') {
                    $is_paid = !$is_bank_transfer;
                } else {
                    $is_paid = false;
                }
                $is_paid = (bool) apply_filters(
                    'alttag_registrations_participant_payment_is_paid',
                    $is_paid,
                    $order,
                    $post_id
                );
                if ($is_paid) {
                    echo '<span style="color:#46b450; font-weight:bold;" title="'
                        . esc_attr(sprintf(__('Order status: %s', 'alttag-registrations'), $status))
                        . '">✓ ' . esc_html__('Paid', 'alttag-registrations') . '</span>';
                } else {
                    echo '<span style="color:#dc3232; font-weight:bold;" title="'
                        . esc_attr(sprintf(__('Order status: %s', 'alttag-registrations'), $status))
                        . '">● ' . esc_html__('Unpaid', 'alttag-registrations') . '</span>';
                }
                break;
            case 'create_date':
                $value = $state ? $state->create_date : '';
                echo $value ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($value))) : '';
                break;
            case 'language':
                $value = $state ? $state->language() : $ctx->defaultLanguage();

                if ($ctx->hasPolylang()) {
                    $lang = \PLL()->model->get_language($value);
                    if ($lang) {
                        echo '<span title="' . esc_attr($lang->name) . '">' . strtoupper($value) . '</span>';
                        break;
                    }
                }

                echo '<span>' . esc_html(strtoupper($value)) . '</span>';
                break;
            case 'is_livestream_user':
                $is_livestream = $state ? $state->isLivestream() : false;
                if ($is_livestream) {
                    echo '<span style="color: #46b450; font-weight: bold;">✓ ' . __('Yes', 'alttag-registrations') . '</span>';
                } else {
                    echo '<span style="color: #999;">✗ ' . __('No', 'alttag-registrations') . '</span>';
                }
                break;
            case 'livestream_access':
                $value = $state ? $state->livestreamAccessStatus() : LivestreamManager::NOT_GRANTED_STATE;
                $options = [
                    LivestreamManager::NOT_GRANTED_STATE => __('Not Granted', 'alttag-registrations'),
                    LivestreamManager::PENDING_STATE => __('Pending', 'alttag-registrations'),
                    LivestreamManager::GRANTED_STATE => __('Granted', 'alttag-registrations'),
                    LivestreamManager::FAILED_STATE => __('Failed', 'alttag-registrations'),
                ];
                $status_colors = [
                    LivestreamManager::NOT_GRANTED_STATE => '#999',
                    LivestreamManager::PENDING_STATE => '#ffb900',
                    LivestreamManager::GRANTED_STATE => '#46b450',
                    LivestreamManager::FAILED_STATE => '#dc3232',
                ];
                $color = $status_colors[$value] ?? '#999';
                $label = $options[$value] ?? '-';
                echo '<span style="color: ' . esc_attr($color) . '; font-weight: bold;">' . esc_html($label) . '</span>';
                break;
            case 'actions':
                $nonce = wp_create_nonce('resend_email_with_ticket_and_invoice_' . $post_id);
                $state = \Alttag\Registrations\ParticipantState::get($post_id);
                $label = self::getResendEmailButtonLabel($state);
                $btn_style = 'white-space: normal; text-align: center; line-height: normal; padding: 6px; display: block; margin-bottom: 4px;';
                echo '<a href="' . admin_url('admin-post.php?action=resend_email_with_ticket_and_invoice&participant_id=' . $post_id . '&_wpnonce=' . $nonce) . '" class="button button-small" style="' . esc_attr($btn_style) . '">' .
                    esc_html($label) . '</a>';
                echo '<a href="' . esc_url(\Alttag\Registrations\AdminPreview::emailUrl($post_id)) . '" target="_blank" rel="noopener" class="button button-small" style="' . esc_attr($btn_style) . '">' .
                    esc_html__('Preview email', 'alttag-registrations') . '</a>';
                break;
        }
    }
    
    /**
     * Set sortable columns
     *
     * @param array $columns The columns to sort
     *
     * @return array The sorted columns
     */
    public function setSortableColumns($columns)
    {
        $columns['first_name'] = 'first_name';
        $columns['last_name'] = 'last_name';
        $columns['email'] = 'email';
        $columns['registration_status'] = 'registration_status';
        $columns['create_date'] = 'create_date';

        // Add language as sortable if Polylang is active
        if (\Alttag\Registrations\RegistrationContext::current()->isMultilingual()) {
            $columns['language'] = 'language';
        }

        $columns = apply_filters('alttag_registrations_participant_sortable_columns', $columns);

        return $columns;
    }
    
    /**
     * Build WP_Query args (meta_query + date_query) from the participant
     * admin list filter $_GET parameters. Shared by the parse_query callback
     * that drives the on-screen list AND by the export handlers that need
     * to apply the same filter set to a fresh query.
     *
     * @return array{meta_query: array, date_query: array}
     */
    public static function buildQueryArgsFromFilters(): array
    {
        $meta_query = [];

        if (!empty($_GET['registration_status'])) {
            $status = sanitize_text_field(wp_unslash((string) $_GET['registration_status']));
            if ($status === 'pending') {
                $meta_query[] = [
                    'relation' => 'OR',
                    ['key' => 'registration_status', 'value' => 'pending', 'compare' => '='],
                    ['key' => 'registration_status', 'compare' => 'NOT EXISTS'],
                ];
            } else {
                $meta_query[] = ['key' => 'registration_status', 'value' => $status, 'compare' => '='];
            }
        }

        if (!empty($_GET['participant_language'])
            && \Alttag\Registrations\RegistrationContext::current()->isMultilingual()) {
            $meta_query[] = [
                'key' => 'language',
                'value' => sanitize_text_field(wp_unslash((string) $_GET['participant_language'])),
                'compare' => '=',
            ];
        }

        if (!empty($_GET['used_coupon'])) {
            $coupon_value = sanitize_text_field(wp_unslash((string) $_GET['used_coupon']));
            if ($coupon_value === '__none__') {
                $meta_query[] = [
                    'relation' => 'OR',
                    ['key' => 'used_coupons', 'value' => '', 'compare' => '='],
                    ['key' => 'used_coupons', 'compare' => 'NOT EXISTS'],
                ];
            } else {
                $meta_query[] = ['key' => 'used_coupons', 'value' => $coupon_value, 'compare' => 'LIKE'];
            }
        }

        if (!empty($_GET['imported_filter'])) {
            $imported_value = sanitize_text_field(wp_unslash((string) $_GET['imported_filter']));
            if ($imported_value === 'imported') {
                $meta_query[] = ['key' => 'imported_at', 'compare' => 'EXISTS'];
            } elseif ($imported_value === 'not_imported') {
                $meta_query[] = ['key' => 'imported_at', 'compare' => 'NOT EXISTS'];
            }
        }

        if (!empty($_GET['participant_product'])) {
            $meta_query[] = [
                'key' => 'product_id',
                'value' => (int) $_GET['participant_product'],
                'compare' => '=',
            ];
        }

        if (!empty($_GET['participant_wave'])) {
            $meta_query[] = [
                'key' => \Alttag\Registrations\GroupSeating::META_WAVE,
                'value' => sanitize_text_field(wp_unslash((string) $_GET['participant_wave'])),
                'compare' => '=',
            ];
        }

        if (!empty($_GET['participant_group'])) {
            $meta_query[] = [
                'key' => \Alttag\Registrations\GroupSeating::META_GROUP,
                'value' => sanitize_text_field(wp_unslash((string) $_GET['participant_group'])),
                'compare' => '=',
            ];
        }

        $meta_query = apply_filters('alttag_registrations_participant_filters_query', $meta_query);

        $date_query = [];
        if (!empty($_GET['participant_from'])) {
            $from = sanitize_text_field(wp_unslash((string) $_GET['participant_from']));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                $date_query[] = ['after' => $from . ' 00:00:00', 'inclusive' => true];
            }
        }
        if (!empty($_GET['participant_to'])) {
            $to = sanitize_text_field(wp_unslash((string) $_GET['participant_to']));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                $date_query[] = ['before' => $to . ' 23:59:59', 'inclusive' => true];
            }
        }
        if (!empty($date_query)) {
            $date_query['column'] = 'post_date';
        }

        return [
            'meta_query' => $meta_query,
            'date_query' => $date_query,
        ];
    }

    /**
     * Names of every filter $_GET param the participant list supports.
     * Used to (a) detect whether any filter is active and (b) propagate the
     * full filter set through to the export-filtered URL.
     */
    public static function getFilterQueryArgNames(): array
    {
        return apply_filters('alttag_registrations_participant_filter_arg_names', [
            'registration_status',
            'participant_language',
            'used_coupon',
            'imported_filter',
            'participant_product',
            'participant_wave',
            'participant_group',
            'participant_hotel',
            'participant_from',
            'participant_to',
        ]);
    }

    /**
     * @return bool true when at least one filter arg is set in $_GET.
     */
    public static function hasActiveFilters(): bool
    {
        foreach (self::getFilterQueryArgNames() as $arg) {
            $value = $_GET[$arg] ?? '';
            if (is_array($value)) {
                $value = array_filter($value, static function ($v) {
                    return $v !== '' && $v !== null;
                });
                if (!empty($value)) {
                    return true;
                }
            } elseif ($value !== '' && $value !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build the $_GET subset that should be propagated to an export URL so
     * the exported file matches the currently filtered list.
     */
    public static function getFilterQueryArgs(): array
    {
        $out = [];
        foreach (self::getFilterQueryArgNames() as $arg) {
            if (!isset($_GET[$arg])) {
                continue;
            }
            $value = $_GET[$arg];
            if (is_array($value)) {
                $value = array_values(array_filter(
                    array_map(static function ($v) {
                        return sanitize_text_field(wp_unslash((string) $v));
                    }, $value),
                    static function ($v) {
                        return $v !== '';
                    }
                ));
                if (!empty($value)) {
                    $out[$arg] = $value;
                }
            } else {
                $value = sanitize_text_field(wp_unslash((string) $value));
                if ($value !== '') {
                    $out[$arg] = $value;
                }
            }
        }
        return $out;
    }

    /**
     * Add Export All button
     *
     * @param string $which The location of the extra table nav markup: 'top' or 'bottom'.
     *
     * @return void
     */
    public function addExportAllButton($which)
    {
        global $typenow;

        // Only show on the participants list page and at the top of the table
        if ($typenow !== 'participant' || $which !== 'top') {
            return;
        }

        // Excel export button
        $excel_nonce = wp_create_nonce('export_all_participants');
        $excel_url = admin_url('admin-post.php?action=export_all_participants&_wpnonce=' . $excel_nonce);

        if (!empty($_GET['registration_status'])) {
            $excel_url = add_query_arg(
                'registration_status',
                sanitize_text_field($_GET['registration_status']),
                $excel_url
            );
        }

        // Tickets ZIP export button
        $tickets_nonce = wp_create_nonce('export_all_tickets');
        $tickets_url = admin_url('admin-post.php?action=export_all_tickets&_wpnonce=' . $tickets_nonce);

        if (!empty($_GET['registration_status'])) {
            $tickets_url = add_query_arg(
                'registration_status',
                sanitize_text_field($_GET['registration_status']),
                $tickets_url
            );
        }

        // Placement and spacing come from printListTableStyles(): the buttons sit on
        // the bulk actions line, aligned right. No inline styles, they would win
        // over that CSS and leave the first button with a stray left margin.
        echo '<div class="alttag-export-actions">';

        // Export Filtered button — visible only when at least one filter is
        // active, sits before the unconditional "Export All" so admin can
        // export the currently shown subset without losing the all-export.
        if (self::hasActiveFilters()) {
            $filtered_nonce = wp_create_nonce('export_filtered_participants');
            $filtered_url = admin_url(
                'admin-post.php?action=export_filtered_participants&_wpnonce=' . $filtered_nonce
            );
            $filtered_url = add_query_arg(self::getFilterQueryArgs(), $filtered_url);
            echo '<a href="' . esc_url($filtered_url) . '" class="button button-primary">'
                . esc_html__('Export Filtered to Excel', 'alttag-registrations') . '</a>';
        }

        // Selected-rows export. Hidden until something is ticked; the script below
        // fills in the count and hands the work to the export_to_excel bulk
        // action, so there is no second export path to keep in sync.
        printf(
            '<button type="button" class="button button-primary alttag-export-selected" hidden'
            . ' data-label="%s">%s</button>',
            esc_attr__('Export selected to Excel (%d)', 'alttag-registrations'),
            esc_html__('Export selected to Excel', 'alttag-registrations')
        );

        echo '<a href="' . esc_url($excel_url) . '" class="button button-primary">' .
            __('Export All to Excel', 'alttag-registrations') . '</a>';
        echo '<a href="' . esc_url($tickets_url) . '" class="button button-primary">' .
            __('Download All Tickets', 'alttag-registrations') . '</a>';
        echo '</div>';

        $this->printSelectedExportScript();
    }

    /**
     * Show the selected-rows export button while rows are ticked.
     *
     * Kept to the existing bulk action on purpose: the button only sets the bulk
     * dropdown and submits the list table form, so capability checks and the
     * form nonce are exactly the ones core already applies.
     */
    private function printSelectedExportScript()
    {
        ?>
        <script>
        (function () {
            var button = document.querySelector('.alttag-export-selected');
            var form = document.getElementById('posts-filter');
            if (!button || !form) {
                return;
            }

            var template = button.getAttribute('data-label') || '';

            function selectedIds() {
                return form.querySelectorAll('input[name="post[]"]:checked').length;
            }

            function refresh() {
                var count = selectedIds();
                button.hidden = count === 0;
                if (count > 0 && template) {
                    button.textContent = template.replace('%d', count);
                }
            }

            form.addEventListener('change', function (event) {
                var target = event.target;
                if (target && (target.name === 'post[]' || target.type === 'checkbox')) {
                    refresh();
                }
            });

            button.addEventListener('click', function () {
                if (selectedIds() === 0) {
                    return;
                }
                var select = form.querySelector('select[name="action"]');
                if (!select) {
                    return;
                }
                select.value = 'export_to_excel';
                var submit = form.querySelector('#doaction');
                if (submit) {
                    submit.click();
                } else {
                    form.submit();
                }
            });

            refresh();
        }());
        </script>
        <?php
    }
    
    /**
     * Add participant filters
     *
     * @return void
     */
    public function addParticipantFilters()
    {
        global $typenow;
        if ($typenow !== 'participant') {
            return;
        }

        $metaFields = $this->manager->getMetaFields();

        do_action('alttag_registrations_participant_before_filters');

        // Product filter — lists every product that has at least one
        // participant registered against it. The product_id meta is set on
        // participant creation, so the dropdown stays accurate without any
        // extra configuration.
        $product_options = $this->getProductFilterOptions();
        if (!empty($product_options)) {
            $current_product = isset($_GET['participant_product'])
                ? (int) $_GET['participant_product']
                : 0;
            echo '<select name="participant_product">';
            echo '<option value="">' . esc_html__('All Products', 'alttag-registrations') . '</option>';
            foreach ($product_options as $pid => $label) {
                printf(
                    '<option value="%d" %s>%s</option>',
                    (int) $pid,
                    selected($current_product, (int) $pid, false),
                    esc_html($label)
                );
            }
            echo '</select>';
        }

        // Seating wave and group filter. The Group capacity screen links here
        // with both set, so clicking a booked number lists the people at that
        // exact station. Options come from the participants themselves.
        $seating_waves = $this->getSeatingFilterValues(\Alttag\Registrations\GroupSeating::META_WAVE);
        if (!empty($seating_waves)) {
            $current_wave = isset($_GET['participant_wave']) ? sanitize_text_field(wp_unslash($_GET['participant_wave'])) : '';
            echo '<select name="participant_wave">';
            echo '<option value="">' . esc_html__('All time slots', 'alttag-registrations') . '</option>';
            foreach ($seating_waves as $wave_value) {
                printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr($wave_value),
                    selected($current_wave, $wave_value, false),
                    esc_html($wave_value)
                );
            }
            echo '</select>';
        }

        $seating_groups = $this->getSeatingFilterValues(\Alttag\Registrations\GroupSeating::META_GROUP);
        if (!empty($seating_groups)) {
            $current_group = isset($_GET['participant_group']) ? sanitize_text_field(wp_unslash($_GET['participant_group'])) : '';
            echo '<select name="participant_group">';
            echo '<option value="">' . esc_html__('All groups', 'alttag-registrations') . '</option>';
            foreach ($seating_groups as $group_value) {
                printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr($group_value),
                    selected($current_group, $group_value, false),
                    esc_html($group_value)
                );
            }
            echo '</select>';
        }

        // Registration Status filter
        $current_registration_status = isset($_GET['registration_status']) ? sanitize_text_field($_GET['registration_status']) : '';
        echo '<select name="registration_status">';
        echo '<option value="">' . __('All Registration Statuses', 'alttag-registrations') . '</option>';
        foreach ($metaFields['registration_status']['options'] as $value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($current_registration_status, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';

        // Language filter (only if Polylang is active)
        $ctx = \Alttag\Registrations\RegistrationContext::current();
        if ($ctx->isMultilingual()) {
            $languages = $ctx->languages();

            if (!empty($languages)) {
                $current_language = isset($_GET['participant_language']) ? sanitize_text_field($_GET['participant_language']) : '';

                echo '<select name="participant_language">';
                echo '<option value="">' . __('All Languages', 'alttag-registrations') . '</option>';
                foreach ($languages as $value => $label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($value),
                        selected($current_language, $value, false),
                        esc_html($label)
                    );
                }
                echo '</select>';
            }
        }

        // Promo code filter
        $used_coupons = $this->getUsedCoupons();
        if (!empty($used_coupons)) {
            $current_coupon = isset($_GET['used_coupon']) ? sanitize_text_field($_GET['used_coupon']) : '';

            echo '<select name="used_coupon">';
            echo '<option value="">' . __('All Promo Codes', 'alttag-registrations') . '</option>';
            echo '<option value="__none__" ' . selected($current_coupon, '__none__', false) . '>' . __('No Promo Code', 'alttag-registrations') . '</option>';
            foreach ($used_coupons as $coupon) {
                printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr($coupon),
                    selected($current_coupon, $coupon, false),
                    esc_html(strtoupper($coupon))
                );
            }
            echo '</select>';
        }

        // Imported filter
        $current_imported = isset($_GET['imported_filter']) ? sanitize_text_field($_GET['imported_filter']) : '';
        echo '<select name="imported_filter">';
        echo '<option value="">' . __('All Participants', 'alttag-registrations') . '</option>';
        printf(
            '<option value="imported" %s>%s</option>',
            selected($current_imported, 'imported', false),
            esc_html__('Imported', 'alttag-registrations')
        );
        printf(
            '<option value="not_imported" %s>%s</option>',
            selected($current_imported, 'not_imported', false),
            esc_html__('Not Imported', 'alttag-registrations')
        );
        echo '</select>';

        // Date range filter — narrows the list to participants created within
        // a window (operates on post_date, which matches the Create Date
        // column rendering).
        $current_from = isset($_GET['participant_from']) ? sanitize_text_field($_GET['participant_from']) : '';
        $current_to = isset($_GET['participant_to']) ? sanitize_text_field($_GET['participant_to']) : '';
        // Date inputs need visible labels — native <input type="date"> has
        // no readable placeholder, so a "Od / Do" prefix is the only way
        // admins can tell which input is which.
        // No inline margins: the filter bar is a flex row with a gap, and an
        // inline style would win over it and break the spacing once the row wraps.
        $date_input = '<label for="%s" class="alttag-filter-label">%s</label>'
            . '<input type="date" id="%s" name="%s" value="%s" title="%s">';
        printf(
            $date_input,
            'participant_from',
            esc_html__('From', 'alttag-registrations'),
            'participant_from',
            'participant_from',
            esc_attr($current_from),
            esc_attr__('Created from (inclusive)', 'alttag-registrations')
        );
        printf(
            $date_input,
            'participant_to',
            esc_html__('To', 'alttag-registrations'),
            'participant_to',
            'participant_to',
            esc_attr($current_to),
            esc_attr__('Created until (inclusive)', 'alttag-registrations')
        );

        do_action('alttag_registrations_participant_after_filters');
    }
    
    /**
     * Filter the participant query
     *
     * @param WP_Query $query The query object
     */
    public function filterParticipantQuery($query)
    {
        global $pagenow, $typenow;

        if ($pagenow !== 'edit.php' || $typenow !== 'participant' || !is_admin()) {
            return;
        }

        // `parse_query` fires for every WP_Query that runs while the
        // participant admin page is loaded — including any sub-query
        // triggered by a callback hooked into
        // `alttag_registrations_participant_filters_query`
        // (e.g. social-evening.php → find_product_by_category() ->
        // get_posts(post_type=product, tax_query=...)). Without a
        // query-scoped gate this filter would recurse into itself
        // through that sub-query and burn memory until fcgid kills
        // the worker. Only operate on the actual participant query.
        $post_types = (array) $query->get('post_type');
        if (!in_array('participant', $post_types, true)) {
            return;
        }

        $args = self::buildQueryArgsFromFilters();
        if (!empty($args['meta_query'])) {
            $query->set('meta_query', $args['meta_query']);
        }
        if (!empty($args['date_query'])) {
            $query->set('date_query', $args['date_query']);
        }

        // Handle sorting. ID DESC tiebreaker stabilizes pagination: bulk
        // imports produce 20+ participants per second, so without a secondary
        // sort key MySQL shuffles same-timestamp rows between page loads —
        // some rows visible on one page, missing from the next.
        $orderby_value = $query->get('orderby');
        $order_value = $query->get('order') ?: 'DESC';

        if (!empty($orderby_value)) {
            switch ($orderby_value) {
                case 'first_name':
                case 'last_name':
                case 'email':
                case 'registration_status':
                case 'language':
                    $query->set('meta_key', $orderby_value);
                    $query->set('orderby', ['meta_value' => $order_value, 'ID' => 'DESC']);
                    break;
                case 'date':
                case 'post_date':
                    $query->set('orderby', ['post_date' => $order_value, 'ID' => 'DESC']);
                    break;
            }
        } elseif ($query->is_main_query()) {
            $query->set('orderby', ['post_date' => 'DESC', 'ID' => 'DESC']);
        }
    }
    
    /**
     * Add meta box for resending invoice email
     *
     * @return void
     */
    public function addResendEmailWithTicketAndInvoiceMetaBox()
    {
        add_meta_box(
            'participant_resend_email_with_ticket_and_invoice',
            __('Email Actions', 'alttag-registrations'),
            [$this, 'renderResendEmailWithTicketAndInvoiceMetaBox'],
            'participant',
            'side',
            'default'
        );
    }
    
    /**
     * Add meta box for regenerating ticket
     *
     * @return void
     */
    public function addRegenerateTicketMetaBox()
    {
        add_meta_box(
            'participant_regenerate_ticket',
            __('Ticket Actions', 'alttag-registrations'),
            [$this, 'renderRegenerateTicketMetaBox'],
            'participant',
            'side',
            'default'
        );
    }

    /**
     * Add meta box for admin previews
     */
    public function addPreviewsMetaBox()
    {
        add_meta_box(
            'participant_previews',
            __('Previews', 'alttag-registrations'),
            [$this, 'renderPreviewsMetaBox'],
            'participant',
            'side',
            'low'
        );
    }

    public function renderPreviewsMetaBox($post)
    {
        $state = \Alttag\Registrations\ParticipantState::get($post->ID);
        $order_id = $state ? (int) $state->getMeta('order_id') : 0;
        $btn_row = 'white-space: normal; text-align: center; line-height: normal; '
            . 'padding: 6px; display: block; margin-bottom: 6px;';
        ?>
        <p style="margin: 0 0 8px 0;">
            <?php esc_html_e('Open in a new tab — nothing is sent, nothing is stored.', 'alttag-registrations'); ?>
        </p>
        <a href="<?php echo esc_url(\Alttag\Registrations\AdminPreview::emailUrl($post->ID)); ?>"
           target="_blank" rel="noopener" class="button" style="<?php echo esc_attr($btn_row); ?>">
            <?php esc_html_e('Preview email', 'alttag-registrations'); ?>
        </a>
        <?php if ($order_id) : ?>
            <a href="<?php echo esc_url(\Alttag\Registrations\AdminPreview::thankYouUrl($order_id)); ?>"
               target="_blank" rel="noopener" class="button" style="<?php echo esc_attr($btn_row); ?>">
                <?php esc_html_e('Preview thank-you page', 'alttag-registrations'); ?>
            </a>
            <a href="<?php echo esc_url(\Alttag\Registrations\AdminPreview::stripeReceiptUrl($order_id)); ?>"
               target="_blank" rel="noopener" class="button" style="<?php echo esc_attr($btn_row); ?>">
                <?php esc_html_e('Preview Stripe receipt', 'alttag-registrations'); ?>
            </a>
        <?php endif; ?>
        <?php
    }
    
    /**
     * Render meta box for resending invoice email
     *
     * @param WP_Post $post The post object
     * 
     * @return void
     */
    public function renderResendEmailWithTicketAndInvoiceMetaBox($post)
    {
        $nonce = wp_create_nonce('resend_email_with_ticket_and_invoice_' . $post->ID);
        $state = \Alttag\Registrations\ParticipantState::get($post->ID);
        $button_label = self::getResendEmailButtonLabel($state);
        $description = self::getResendEmailDescription($state);
        ?>
        <div class="resend-email-with-ticket-and-invoice-actions">
            <p><?php echo esc_html($description); ?></p>
            <a href="<?php echo admin_url('admin-post.php?action=resend_email_with_ticket_and_invoice&participant_id=' . $post->ID . '&redirect_to=detail&_wpnonce=' . $nonce); ?>"
               class="button button-primary" style="white-space: normal; text-align: center; line-height: normal; padding: 6px;">
                <?php echo esc_html($button_label); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Pick the right "Resend email…" label based on what the participant actually has.
     */
    private static function getResendEmailButtonLabel($state): string
    {
        $ticket = $state ? $state->hasTicket() : false;
        $invoice = $state ? $state->hasInvoice() : false;

        if ($ticket && $invoice) {
            return __('Resend email with ticket and invoice', 'alttag-registrations');
        }
        if ($ticket) {
            return __('Resend email with ticket', 'alttag-registrations');
        }
        if ($invoice) {
            return __('Resend email with invoice', 'alttag-registrations');
        }
        return __('Resend email', 'alttag-registrations');
    }

    private static function getResendEmailDescription($state): string
    {
        $ticket = $state ? $state->hasTicket() : false;
        $invoice = $state ? $state->hasInvoice() : false;

        if ($ticket && $invoice) {
            return __('Resend the email with ticket and invoice to the participant.', 'alttag-registrations');
        }
        if ($ticket) {
            return __('Resend the email with ticket to the participant.', 'alttag-registrations');
        }
        if ($invoice) {
            return __('Resend the email with invoice to the participant.', 'alttag-registrations');
        }
        return __('Resend the email to the participant.', 'alttag-registrations');
    }
    
    /**
     * Render meta box for regenerating ticket
     *
     * @param WP_Post $post The post object
     * 
     * @return void
     */
    public function renderRegenerateTicketMetaBox($post)
    {
        $nonce = wp_create_nonce('regenerate_ticket_' . $post->ID);

        ?>
        <div class="regenerate-ticket-actions">
            <p><?php _e('Regenerate the ticket for the participant.', 'alttag-registrations'); ?></p>
            <a href="<?php echo admin_url('admin-post.php?action=regenerate_ticket&participant_id=' . $post->ID . '&redirect_to=detail&_wpnonce=' . $nonce); ?>"
               class="button button-primary">
                <?php _e('Regenerate Ticket', 'alttag-registrations'); ?>
            </a>

            <?php
            // Allow adding additional actions to this meta box
            do_action('alttag_registrations_after_regenerate_ticket_button', $post);
            ?>
        </div>
        <?php
    }

    /**
     * Detect which Riverstream access type applies to a participant's order.
     * Recording takes precedence when an order mixes both kinds.
     *
     * @return 'recording'|'live'|null Null when no Riverstream-eligible product.
     */
    private function detectRiverstreamAccessType($context)
    {
        $order = $context ? $context->order() : null;
        if (!$order instanceof \WC_Order) {
            return null;
        }
        $core = \Alttag\Registrations\Core::getInstance();
        $recording = $core->moduleRegistry ? $core->moduleRegistry->get('recording') : null;
        if ($recording && method_exists($recording, 'orderHasRecording') && $recording->orderHasRecording($order)) {
            return 'recording';
        }
        $state = $context->participant();
        $participant_id = $state ? $state->id : 0;
        if ($participant_id && \Alttag\Registrations\is_livestream_user($participant_id)) {
            return 'live';
        }
        return null;
    }

    public function addReimportRiverstreamMetaBox()
    {
        add_meta_box(
            'participant_reimport_riverstream',
            __('Riverstream Access', 'alttag-registrations'),
            [$this, 'renderReimportRiverstreamMetaBox'],
            'participant',
            'side',
            'default'
        );
    }

    public function renderReimportRiverstreamMetaBox($post)
    {
        $context = \Alttag\Registrations\ctx()->withParticipant($post->ID);
        $access_type = $this->detectRiverstreamAccessType($context);

        if ($access_type === null) {
            echo '<p>' . esc_html__(
                'This order has no livestream or recording product — Riverstream webhook does not apply.',
                'alttag-registrations'
            ) . '</p>';
            return;
        }

        $state = $context->participant();
        $meta_key = $access_type === 'recording' ? 'recording_access' : 'livestream_access';
        $current = (string) $state->getMeta($meta_key);
        if ($current === '') {
            $current = 'not_granted';
        }

        $nonce = wp_create_nonce('reimport_riverstream_' . $post->ID);
        $url = admin_url(
            'admin-post.php?action=reimport_riverstream&participant_id=' . $post->ID
            . '&_wpnonce=' . $nonce
        );

        $label = $access_type === 'recording'
            ? __('Reimport to Riverstream (recording)', 'alttag-registrations')
            : __('Reimport to Riverstream (livestream)', 'alttag-registrations');
        ?>
        <p style="margin: 0 0 10px 0;">
            <?php
            printf(
                /* translators: 1: access type, 2: current grant state */
                esc_html__('Access type: %1$s — current state: %2$s', 'alttag-registrations'),
                '<strong>' . esc_html($access_type) . '</strong>',
                '<strong>' . esc_html($current) . '</strong>'
            );
            ?>
        </p>
        <a href="<?php echo esc_url($url); ?>" class="button button-primary" style="width: 100%; text-align: center;">
            <?php echo esc_html($label); ?>
        </a>
        <p class="description" style="margin-top: 8px;">
            <?php esc_html_e(
                'Resends the registration webhook to Riverstream so the user is (re)assigned to the proper stage.',
                'alttag-registrations'
            ); ?>
        </p>
        <?php
    }

    /**
     * Handle the "Reimport to Riverstream" admin-post action.
     */
    public function handleReimportRiverstream()
    {
        $pid = isset($_GET['participant_id']) ? (int) $_GET['participant_id'] : 0;
        if (!$pid) {
            wp_die(esc_html__('Missing participant_id', 'alttag-registrations'));
        }
        $nonce = $_GET['_wpnonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'reimport_riverstream_' . $pid)) {
            wp_die(esc_html__('Invalid nonce', 'alttag-registrations'));
        }
        if (!current_user_can('edit_post', $pid)) {
            wp_die(esc_html__('Forbidden', 'alttag-registrations'));
        }

        $context = \Alttag\Registrations\ctx()->withParticipant($pid);
        $access_type = $this->detectRiverstreamAccessType($context);
        $success = false;

        if ($access_type !== null) {
            // Reset access meta so the sender doesn't short-circuit on
            // "already granted".
            $state = $context->participant();
            if ($state) {
                $meta_key = $access_type === 'recording' ? 'recording_access' : 'livestream_access';
                $state->setMeta($meta_key, '');
            }

            $livestream = \Alttag\Registrations\Core::getInstance()->moduleRegistry->get('livestream');
            if ($livestream && method_exists($livestream, 'sendRiverstreamWebhook')) {
                $success = (bool) $livestream->sendRiverstreamWebhook($context, $access_type, []);
            }
        }

        $redirect = get_edit_post_link($pid, '');
        $redirect = add_query_arg('riverstream_resent', $success ? '1' : '0', $redirect);
        wp_safe_redirect($redirect);
        exit;
    }
    
    /**
     * Display admin notices for ticket regeneration and email resending
     *
     * @return void
     */
    public function displayAdminNotices()
    {
        global $pagenow, $post, $typenow;
        
        // Check if we're on the participant edit screen or participants list
        $is_edit_screen = ($pagenow === 'post.php' && $post && get_post_type($post) === 'participant');
        $is_list_screen = ($pagenow === 'edit.php' && $typenow === 'participant');
        
        if (!$is_edit_screen && !$is_list_screen) {
            return;
        }
        
        // Check for Riverstream reimport message (only on edit screen)
        if ($is_edit_screen && isset($_GET['riverstream_resent'])) {
            $success = $_GET['riverstream_resent'] === '1';
            if ($success) {
                echo '<div class="notice notice-success is-dismissible"><p>'
                    . esc_html__('Riverstream webhook resent successfully.', 'alttag-registrations')
                    . '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>'
                    . esc_html__('Riverstream webhook failed — check the registration history.', 'alttag-registrations')
                    . '</p></div>';
            }
        }

        // Check for ticket regeneration message (only on edit screen)
        if ($is_edit_screen && isset($_GET['ticket_regenerated'])) {
            $success = $_GET['ticket_regenerated'] === '1';
            
            if ($success) {
                echo '<div class="notice notice-success is-dismissible"><p>' . 
                    __('Ticket was successfully regenerated.', 'alttag-registrations') . 
                    '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' . 
                    __('Failed to regenerate ticket. Please try again.', 'alttag-registrations') . 
                    '</p></div>';
            }
        }
        
        // Check for email resend message (on both screens)
        if (isset($_GET['emails_sent']) || isset($_GET['emails_failed'])) {
            $success_count = isset($_GET['emails_sent']) ? intval($_GET['emails_sent']) : 0;
            $error_count = isset($_GET['emails_failed']) ? intval($_GET['emails_failed']) : 0;
            
            if ($success_count > 0) {
                echo '<div class="notice notice-success is-dismissible"><p>' . 
                    sprintf(_n(
                        'Email successfully sent to %d participant.',
                        'Email successfully sent to %d participants.',
                        $success_count,
                        'alttag-registrations'
                    ), $success_count) . 
                    '</p></div>';
            }
            
            if ($error_count > 0) {
                echo '<div class="notice notice-error is-dismissible"><p>' . 
                    sprintf(_n(
                        'Failed to send email to %d participant.',
                        'Failed to send email to %d participants.',
                        $error_count,
                        'alttag-registrations'
                    ), $error_count) . 
                    '</p></div>';
            }
        }
    }

    /**
     * Register the bulk actions
     *
     * @param array $bulk_actions Array of bulk actions
     * 
     * @return array Modified array of bulk actions
     */
    public function registerBulkActions($bulk_actions)
    {
        $bulk_actions['email_participants'] = __('Send Email', 'alttag-registrations');
        return $bulk_actions;
    }

    /**
     * Handle the bulk actions
     *
     * @param string $redirect_to The redirect URL
     * @param string $doaction The action being taken
     * @param array $post_ids The items to take the action on
     * 
     * @return string Modified redirect URL
     */
    public function handleBulkActions($redirect_to, $doaction, $post_ids)
    {
        if ($doaction === 'email_participants') {
            // Verify user capabilities
            if (!current_user_can('edit_posts')) {
                wp_die(__('You do not have permission to send emails to participants', 'alttag-registrations'));
            }

            if (empty($post_ids)) {
                wp_die(__('No participants selected for email', 'alttag-registrations'));
            }

            // Store participant IDs in transient for use in email page
            set_transient('alttag_email_participants', $post_ids, 60 * 10); // 10 minutes expiration

            // Redirect to email page
            $redirect_to = admin_url('edit.php?post_type=participant&page=alttag-email');
            return $redirect_to;
        }

        return $redirect_to;
    }

    /**
     * Handle AJAX request to regenerate participant ticket
     */
    public function handleRegenerateParticipantTicket()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'regenerate_participant_ticket')) {
            wp_send_json_error(['message' => __('Security check failed', 'alttag-registrations')]);
        }

        // Verify user capabilities
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action', 'alttag-registrations')]);
        }

        // Get participant ID
        $participant_id = isset($_POST['participant_id']) ? intval($_POST['participant_id']) : 0;
        if (!$participant_id) {
            wp_send_json_error(['message' => __('Invalid participant ID', 'alttag-registrations')]);
        }

        // Get verification manager
        $verificationManager = apply_filters('alttag_registrations_verification_manager', null);
        if (!$verificationManager) {
            wp_send_json_error(['message' => __('Verification manager not available', 'alttag-registrations')]);
        }

        // Regenerate ticket
        $result = $verificationManager->generateAndSaveTicket($participant_id);
        if (!$result) {
            wp_send_json_error(['message' => __('Failed to regenerate ticket', 'alttag-registrations')]);
        }

        // Log regeneration in participant history
        $state = \Alttag\Registrations\ParticipantState::get($participant_id);
        if ($state) {
            $state->addToHistory(__('Ticket regenerated', 'alttag-registrations'));
        }

        wp_send_json_success(['message' => __('Ticket regenerated successfully', 'alttag-registrations')]);
    }

    /**
     * Get all unique coupon codes used by participants
     *
     * @return array List of unique coupon codes
     */
    /**
     * Build the option map for the product filter dropdown — every product
     * that has at least one participant record points to it via the
     * `product_id` post meta. The list is cached for the duration of the
     * request so it does not hit the DB twice if the filter renders again.
     *
     * @return array<int, string> [product_id => label]
     */
    /**
     * Distinct seating meta values (wave times, group letters) that exist on
     * participant posts. Drives the wave/group admin list filters.
     */
    private function getSeatingFilterValues(string $meta_key): array
    {
        global $wpdb;

        $cache_key = 'alttag_participant_seating_filter_' . md5($meta_key);
        $values = wp_cache_get($cache_key);
        if (is_array($values)) {
            return $values;
        }

        $values = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.meta_value
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = %s
              AND p.post_type = 'participant'
              AND p.post_status != 'trash'
            ORDER BY pm.meta_value",
            $meta_key
        ));

        wp_cache_set($cache_key, $values);

        return $values;
    }

    private function getProductFilterOptions()
    {
        global $wpdb;

        $cache_key = 'alttag_participant_product_filter_options';
        $options = wp_cache_get($cache_key);
        if (is_array($options)) {
            return $options;
        }

        $ids = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = 'product_id'
              AND pm.meta_value REGEXP '^[0-9]+$'
              AND p.post_type = 'participant'"
        );

        $options = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (!$id) {
                continue;
            }
            $title = get_the_title($id);
            $options[$id] = $title !== ''
                ? self::productFilterLabel($id, $title)
                : sprintf('#%d', $id);
        }

        // Sort by label so the dropdown is alphabetical.
        natcasesort($options);

        wp_cache_set($cache_key, $options, '', 300); // Cache for 5 minutes
        return $options;
    }

    /**
     * Date-disambiguated label for the product filter.
     *
     * Several products are titled after the event only, so the same name shows
     * up once per edition and the dropdown gives no way to tell them apart. The
     * event date is appended — the full date for a one-day event, the year
     * alone for a multi-day one, where spelling out a range would make the
     * option unreadable.
     *
     * A title that already carries a year is left alone: it is already
     * unambiguous and the post_title is never modified here. The year is
     * matched on the DECODED title — get_the_title() returns entities, and
     * `&#8211;` (the en dash these titles are full of) contains four digits,
     * so a bare \d{4} test treated every dashed title as already dated.
     *
     * @param int    $product_id
     * @param string $title
     * @return string
     */
    private static function productFilterLabel($product_id, $title)
    {
        $decoded = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
        if (preg_match('/\b(?:19|20)\d{2}\b/', $decoded)) {
            return $title;
        }

        $dates = self::productEventDates((int) $product_id);
        if (empty($dates)) {
            return $title;
        }

        sort($dates);
        $timestamp = strtotime($dates[0]);
        if (!$timestamp) {
            return $title;
        }

        return $title . ' (' . date_i18n(count($dates) > 1 ? 'Y' : 'j. n. Y', $timestamp) . ')';
    }

    /**
     * Every Y-m-d the product runs on, de-duplicated and unsorted.
     *
     * `_session_dates` only exists on products created with the session module;
     * the older ones (Vesmírny piatok 2026-03-13 and friends) carry their date
     * in `_event_date_start` / `_event_date_end` instead. Both are read through
     * the same fallback chain the rest of the plugin uses for a product's event
     * date, so the filter label cannot disagree with the e-mails.
     *
     * @param int $product_id
     * @return array<int, string>
     */
    private static function productEventDates($product_id)
    {
        $dates = [];

        foreach ((array) get_post_meta($product_id, '_session_dates', true) as $row) {
            $date = is_array($row) ? trim((string) ($row['date'] ?? '')) : '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $dates[$date] = true;
            }
        }

        if (empty($dates)) {
            foreach (['general.event_date_start', 'general.event_date_end'] as $key) {
                $date = trim(\Alttag\Registrations\Settings::getValue($key, $product_id));
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $dates[$date] = true;
                }
            }
        }

        return array_keys($dates);
    }

    private function getUsedCoupons()
    {
        global $wpdb;

        $cache_key = 'alttag_participant_used_coupons';
        $coupons = wp_cache_get($cache_key);

        if (false === $coupons) {
            $results = $wpdb->get_col(
                "SELECT DISTINCT meta_value
                FROM {$wpdb->postmeta}
                WHERE meta_key = 'used_coupons'
                AND meta_value != ''
                AND meta_value IS NOT NULL"
            );

            $coupons = [];
            foreach ($results as $value) {
                // Handle comma-separated coupon codes
                $codes = array_map('trim', explode(',', $value));
                foreach ($codes as $code) {
                    if (!empty($code) && !in_array($code, $coupons)) {
                        $coupons[] = $code;
                    }
                }
            }

            sort($coupons);
            wp_cache_set($cache_key, $coupons, '', 300); // Cache for 5 minutes
        }

        return $coupons;
    }
} 
