<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Where the assigned station is shown and managed.
 *
 * Verify page: as the FIRST section, because at check-in the only thing the
 * person on the door needs after scanning the QR code is which station to send
 * the visitor to.
 *
 * Deliberately NOT on the PDF ticket: the station can be changed in the admin
 * and a printed ticket would then be stale. The verify page is always live.
 */
class GroupSeatingUI
{
    private const FIELD = 'seating_group';
    private const PAGE_SLUG = 'alttag-group-seating';
    private const RECALC_ACTION = 'alttag_seating_recalc';
    private const FAKE_OPTION = 'alttag_seating_fake_seats';
    private const FAKE_INPUT = 'alttag_fake_seats';
    private const FAKE_PRODUCT = 'alttag_fake_product';
    private const FAKE_NONCE = 'alttag_fake_nonce';
    private const SEATS_INPUT = 'alttag_participant_seats';
    private const SEATS_NONCE = 'alttag_participant_seats_nonce';

    public function registerHooks()
    {
        add_filter('alttag_registrations_verification_sections', [$this, 'addSection'], 5);
        add_filter('alttag_registrations_customer_data', [$this, 'addCustomerData'], 20, 3);
        add_filter('alttag_registrations_verification_field_label', [$this, 'fieldLabel'], 20, 3);

        add_filter('manage_participant_posts_columns', [$this, 'addColumn'], 20);
        add_action('manage_participant_posts_custom_column', [$this, 'renderColumn'], 20, 2);
        add_action('add_meta_boxes', [$this, 'addMetaBox']);
        add_action('save_post_participant', [$this, 'saveMetaBox'], 10, 2);
        add_action('save_post_participant', [$this, 'saveSeatCounts'], 20, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueParticipantAssets']);
        add_action('admin_notices', [$this, 'showSeatCountNotice']);

        add_action('admin_menu', [$this, 'addOverviewPage']);

        // Ticket: {seating_wave} for the designer. The station is deliberately
        // not exposed, see the class docblock.
        add_filter('alttag_registrations_ticket_shortcodes', [$this, 'addWaveShortcodes'], 20, 3);
    }

    // ------------------------------------------------------------ verify page

    /** Put seating first: it is the reason the page is opened at check-in. */
    public function addSection($sections)
    {
        if (!is_array($sections)) {
            return $sections;
        }

        return array_merge([
            'seating' => [
                'title' => __('Group', 'alttag-registrations'),
                'fields' => [self::FIELD],
                // Rendered big and on an accent background: at the door this is
                // the only thing that has to be readable at a glance.
                'class' => 'fancy_box--highlight',
            ],
        ], $sections);
    }

    public function addCustomerData($data, $participant_id = 0, $participant = null)
    {
        if (!is_array($data)) {
            return $data;
        }

        $label = GroupSeating::label((int) $participant_id);
        if ($label !== '') {
            $data[self::FIELD] = $label;
        }

        return $data;
    }

    public function fieldLabel($label, $field_id = '', $customerData = [])
    {
        return $field_id === self::FIELD
            ? __('Group', 'alttag-registrations')
            : $label;
    }

    // ------------------------------------------------------- participant list

    public function addColumn($columns)
    {
        if (!is_array($columns)) {
            return $columns;
        }

        $out = [];
        foreach ($columns as $key => $title) {
            $out[$key] = $title;
            if ($key === 'title') {
                $out['seating_group'] = __('Group', 'alttag-registrations');
            }
        }

        return isset($out['seating_group'])
            ? $out
            : $out + ['seating_group' => __('Group', 'alttag-registrations')];
    }

    public function renderColumn($column, $post_id)
    {
        if ($column !== 'seating_group') {
            return;
        }

        $label = GroupSeating::label((int) $post_id);
        echo $label !== '' ? esc_html($label) : '-';
    }

    // -------------------------------------------------------- change a station

    public function addMetaBox()
    {
        add_meta_box(
            'alttag-seating-group',
            __('Group (seating)', 'alttag-registrations'),
            [$this, 'renderMetaBox'],
            'participant',
            'side',
            'default'
        );
    }

    public function renderMetaBox($post)
    {
        $product_id = (int) get_post_meta($post->ID, 'product_id', true);

        if (!GroupSeating::isEnabled($product_id)) {
            echo '<p>' . esc_html__('This product does not use grouped seating.', 'alttag-registrations') . '</p>';
            return;
        }

        $waves = GroupSeating::waves($product_id);
        if (!$waves) {
            echo '<p>' . esc_html__('No time slots are configured for this product.', 'alttag-registrations') . '</p>';
            $this->renderSeatCounts($post, $product_id);
            return;
        }

        $cur_wave = (string) get_post_meta($post->ID, GroupSeating::META_WAVE, true);
        $cur_group = (string) get_post_meta($post->ID, GroupSeating::META_GROUP, true);
        $current = $cur_wave !== '' && $cur_group !== '' ? $cur_wave . '|' . $cur_group : '';

        wp_nonce_field('alttag_seating_group', 'alttag_seating_group_nonce');

        echo '<select name="alttag_seating_group" style="width:100%">';
        echo '<option value="">' . esc_html__('not assigned', 'alttag-registrations') . '</option>';

        foreach ($waves as $time => $wave) {
            $capacity = GroupSeating::capacity($product_id, (string) $time);
            $ledger = GroupSeating::participantLedger($product_id, (string) $time);

            echo '<optgroup label="' . esc_attr($wave['label']) . '">';
            foreach (GroupSeating::groups($product_id, (string) $time) as $group) {
                $taken = (int) ($ledger[$group] ?? 0);
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr($time . '|' . $group),
                    selected($current, $time . '|' . $group, false),
                    esc_html(sprintf('%s (%d/%d)', $group, $taken, $capacity))
                );
            }
            echo '</optgroup>';
        }
        echo '</select>';

        echo '<p class="description">'
            . esc_html__('Moves the whole booking, because a booking sits together in one station. Capacity is not enforced here, the numbers next to each station are shown so you can decide.', 'alttag-registrations')
            . '</p>';

        $this->renderSeatCounts($post, $product_id);
    }

    private function renderSeatCounts($post, int $product_id): void
    {
        $stored = get_post_meta($post->ID, 'selected_participant_types_data', true);
        if (!is_array($stored)) {
            return;
        }

        $types = [];
        foreach ((array) get_post_meta($product_id, '_participant_types', true) as $type) {
            $type_id = is_array($type) ? sanitize_key($type['id'] ?? '') : '';
            if ($type_id !== '') {
                $types[$type_id] = (string) ($type['label'] ?? $type_id);
            }
        }
        foreach ($stored as $type_id => $count) {
            $type_id = sanitize_key((string) $type_id);
            if ($type_id !== '' && !isset($types[$type_id])) {
                $types[$type_id] = $type_id;
            }
        }
        if (!$types) {
            return;
        }

        echo '<div class="alttag-participant-seats">';
        echo '<h4>' . esc_html__('Seat counts', 'alttag-registrations') . '</h4>';
        wp_nonce_field('alttag_participant_seats', self::SEATS_NONCE);

        foreach ($types as $type_id => $label) {
            $count = max(0, (int) ($stored[$type_id] ?? 0));
            echo '<div class="alttag-participant-seats__row">';
            echo '<span class="alttag-participant-seats__label">' . esc_html($label) . '</span>';
            printf(
                '<button type="button" class="button alttag-participant-seats__step" data-delta="-1" aria-label="%s">&minus;</button>',
                esc_attr(sprintf(
                    /* translators: %s: participant type */
                    __('Decrease %s', 'alttag-registrations'),
                    $label
                ))
            );
            printf(
                '<input type="number" class="small-text alttag-participant-seats__count" min="0" max="99999" step="1" name="%s[%s]" value="%d" aria-label="%s">',
                esc_attr(self::SEATS_INPUT),
                esc_attr($type_id),
                $count,
                esc_attr(sprintf(
                    /* translators: %s: participant type */
                    __('Seat count for %s', 'alttag-registrations'),
                    $label
                ))
            );
            printf(
                '<button type="button" class="button alttag-participant-seats__step" data-delta="1" aria-label="%s">+</button>',
                esc_attr(sprintf(
                    /* translators: %s: participant type */
                    __('Increase %s', 'alttag-registrations'),
                    $label
                ))
            );
            echo '</div>';
        }

        echo '<p class="description">'
            . esc_html__('Saving updates occupancy immediately. Zero removes every seat of that type.', 'alttag-registrations')
            . '</p></div>';
    }

    public function saveSeatCounts($post_id, $post = null)
    {
        if (!isset($_POST[self::SEATS_NONCE])) {
            return;
        }
        $nonce = sanitize_text_field(wp_unslash($_POST[self::SEATS_NONCE]));
        if (!wp_verify_nonce($nonce, 'alttag_participant_seats')) {
            return;
        }
        if (($post && $post->post_type !== 'participant')
            || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            || wp_is_post_revision($post_id)
            || !current_user_can('edit_post', $post_id)) {
            return;
        }

        $counts = isset($_POST[self::SEATS_INPUT]) && is_array($_POST[self::SEATS_INPUT])
            ? wp_unslash($_POST[self::SEATS_INPUT])
            : [];
        $result = GroupSeating::updateParticipantSeatCounts((int) $post_id, $counts);
        if ($result['changed']) {
            set_transient($this->seatNoticeKey((int) $post_id), $result['message'], 60);
        }
    }

    public function enqueueParticipantAssets($hook_suffix)
    {
        if (!in_array($hook_suffix, ['post.php', 'post-new.php'], true)) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'participant') {
            return;
        }

        $css = ALTTAG_REGISTRATIONS_PATH . 'assets/css/admin-participant-seats.css';
        $js = ALTTAG_REGISTRATIONS_PATH . 'assets/js/admin-participant-seats.js';
        wp_enqueue_style(
            'alttag-participant-seats',
            ALTTAG_REGISTRATIONS_URL . 'assets/css/admin-participant-seats.css',
            [],
            file_exists($css) ? filemtime($css) : '1.0.0'
        );
        wp_enqueue_script(
            'alttag-participant-seats',
            ALTTAG_REGISTRATIONS_URL . 'assets/js/admin-participant-seats.js',
            [],
            file_exists($js) ? filemtime($js) : '1.0.0',
            true
        );
    }

    public function showSeatCountNotice()
    {
        $screen = get_current_screen();
        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        if (!$screen || $screen->post_type !== 'participant' || $post_id < 1) {
            return;
        }

        $key = $this->seatNoticeKey($post_id);
        $message = get_transient($key);
        if (!is_string($message) || $message === '') {
            return;
        }
        delete_transient($key);
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private function seatNoticeKey(int $post_id): string
    {
        return 'alttag_participant_seats_notice_' . get_current_user_id() . '_' . $post_id;
    }

    public function saveMetaBox($post_id, $post = null)
    {
        if (!isset($_POST['alttag_seating_group_nonce'])
            || !wp_verify_nonce($_POST['alttag_seating_group_nonce'], 'alttag_seating_group')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        if (!array_key_exists('alttag_seating_group', $_POST)) {
            return;
        }

        $product_id = (int) get_post_meta($post_id, 'product_id', true);
        $raw = sanitize_text_field(wp_unslash($_POST['alttag_seating_group']));

        $order_id = (int) get_post_meta($post_id, 'order_id', true);

        if ($raw === '') {
            if ($order_id) {
                GroupSeating::moveOrder($order_id, '', '');
                return;
            }
            delete_post_meta($post_id, GroupSeating::META_GROUP);
            delete_post_meta($post_id, GroupSeating::META_WAVE);
            delete_post_meta($post_id, GroupSeating::META_TIME);
            return;
        }

        [$wave, $group] = array_pad(explode('|', $raw, 2), 2, '');
        $waves = GroupSeating::waves($product_id);

        if (!isset($waves[$wave]) || !in_array($group, GroupSeating::groups($product_id, $wave), true)) {
            return;
        }

        // The whole booking moves, not just this person: the occupancy is counted
        // per order and a booking sits together in one physical station.
        if ($order_id && GroupSeating::moveOrder($order_id, $group, $wave)) {
            return;
        }

        GroupSeating::writeParticipantGroup($post_id, $product_id, $group, $wave);
    }

    // ------------------------------------------------------ capacity overview

    public function addOverviewPage()
    {
        add_submenu_page(
            'edit.php?post_type=participant',
            __('Group capacity', 'alttag-registrations'),
            __('Group capacity', 'alttag-registrations'),
            'edit_posts',
            self::PAGE_SLUG,
            [$this, 'renderOverview']
        );
    }

    public function renderOverview()
    {
        // Run before anything is printed: the tables below must already show the
        // corrected ledger, otherwise the admin sees the numbers from before.
        $notice = $this->maybeRecalculate();
        $notice .= $this->maybeSaveFakeSeats();

        $products = $this->seatingProducts();

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Group capacity', 'alttag-registrations') . '</h1>';
        // Same cap as the handler: a user who cannot run the recalc must not
        // see a button that then does nothing.
        if (current_user_can('manage_woocommerce')) {
            printf(
                ' <a href="%s" class="page-title-action">%s</a>',
                esc_url(wp_nonce_url(
                    add_query_arg(
                        ['post_type' => 'participant', 'page' => self::PAGE_SLUG, 'action' => self::RECALC_ACTION],
                        admin_url('edit.php')
                    ),
                    self::RECALC_ACTION
                )),
                esc_html__('Recalculate groups', 'alttag-registrations')
            );
        }
        echo '<hr class="wp-header-end">';
        echo $notice;

        if (!$products) {
            echo '<p>' . esc_html__('No product uses grouped seating yet. Enable it in the product Inventory tab.', 'alttag-registrations') . '</p></div>';
            return;
        }

        foreach ($products as $product_id) {
            $waves = GroupSeating::waves($product_id);

            echo '<h2>' . esc_html(get_the_title($product_id)) . '</h2>';

            if (!$waves) {
                echo '<p>' . esc_html__('No time slots are configured for this product.', 'alttag-registrations') . '</p>';
                continue;
            }

            $booked = 0;
            $seats = 0;
            $fake = $this->fakeSeats($product_id);
            $fake_total = 0;
            foreach ($waves as $time => $wave) {
                $capacity = GroupSeating::capacity($product_id, (string) $time);
                $booked += array_sum(GroupSeating::participantLedger($product_id, (string) $time));
                $seats += $capacity * count(GroupSeating::groups($product_id, (string) $time));
                foreach (GroupSeating::groups($product_id, (string) $time) as $group) {
                    $fake_total += (int) ($fake[$time . '|' . $group] ?? 0);
                }
            }

            printf(
                '<p><strong>%s</strong>%s</p>',
                esc_html(sprintf(
                    /* translators: 1: booked people, 2: total seats */
                    __('Booked: %1$d of %2$d', 'alttag-registrations'),
                    (int) $booked + $fake_total,
                    (int) $seats
                )),
                $fake_total > 0
                    ? ' <span style="color:#787c82">(+' . (int) $fake_total . ')</span>'
                    : ''
            );

            printf(
                '<p class="description">%s</p>',
                esc_html__(
                    'Only participants with a valid order are counted towards occupancy; cancelled, refunded and failed orders are not.',
                    'alttag-registrations'
                )
            );

            $can_edit = current_user_can('manage_woocommerce');
            if ($can_edit) {
                echo '<form method="post">';
                wp_nonce_field('alttag_seating_fake', self::FAKE_NONCE);
                printf('<input type="hidden" name="%s" value="%d">', esc_attr(self::FAKE_PRODUCT), (int) $product_id);
            }

            echo '<table class="widefat striped" style="max-width:820px;margin-bottom:24px">';
            echo '<thead><tr>'
                . '<th>' . esc_html__('Time slot', 'alttag-registrations') . '</th>'
                . '<th>' . esc_html__('Group', 'alttag-registrations') . '</th>'
                . '<th>' . esc_html__('Booked', 'alttag-registrations') . '</th>'
                . '<th>' . esc_html__('Artificial increase', 'alttag-registrations') . '</th>'
                . '<th>' . esc_html__('Free seats', 'alttag-registrations') . '</th>'
                . '</tr></thead><tbody>';

            foreach ($waves as $time => $wave) {
                $capacity = GroupSeating::capacity($product_id, (string) $time);
                $ledger = GroupSeating::participantLedger($product_id, (string) $time);
                $first = true;

                foreach (GroupSeating::groups($product_id, (string) $time) as $group) {
                    $taken = (int) ($ledger[$group] ?? 0);
                    $fake_seats = (int) ($fake[$time . '|' . $group] ?? 0);
                    $free = max(0, $capacity - $taken - $fake_seats);
                    // The booked number links to the participant list filtered
                    // down to this exact wave and station.
                    $participants_url = add_query_arg([
                        'post_type' => 'participant',
                        'participant_product' => $product_id,
                        'participant_wave' => (string) $time,
                        'participant_group' => $group,
                    ], admin_url('edit.php'));

                    if ($can_edit) {
                        $fake_cell = sprintf(
                            '<input type="number" min="0" max="999" step="1" value="%d" name="%s[%s]" style="width:70px">',
                            $fake_seats,
                            esc_attr(self::FAKE_INPUT),
                            esc_attr($time . '|' . $group)
                        );
                    } else {
                        $fake_cell = $fake_seats > 0 ? '+' . $fake_seats : '-';
                    }

                    printf(
                        '<tr><td>%s</td><td><strong>%s</strong></td><td><a href="%s">%d</a>%s / %d</td><td>%s</td><td%s>%d</td></tr>',
                        $first ? esc_html($wave['label']) : '',
                        esc_html($group),
                        esc_url($participants_url),
                        $taken,
                        $fake_seats > 0 ? '<span style="color:#787c82"> (+' . $fake_seats . ')</span>' : '',
                        $capacity,
                        $fake_cell,
                        $free <= 0 ? ' style="color:#b32d2e;font-weight:600"' : '',
                        $free
                    );
                    $first = false;
                }
            }

            echo '</tbody></table>';

            if ($can_edit) {
                submit_button(__('Save increase', 'alttag-registrations'), 'secondary small', 'submit', false);
                echo '</form>';
            }

            if ($can_edit || $fake_total > 0) {
                printf(
                    '<p class="description">%s</p>',
                    esc_html__('Artificial seats are display-only: they do not create participants and do not change real capacity. Customers see fewer free seats in checkout (at zero it looks sold out), but orders, exports, check-in and e-mails are never blocked.', 'alttag-registrations')
                );
            }
        }

        echo '</div>';
    }

    /**
     * Handle the "Recalculate groups" button and return the notice to print.
     *
     * Returns ready-to-print, already escaped HTML rather than echoing, so the
     * caller can run the fix first and still place the notice under the heading.
     */
    private function maybeRecalculate(): string
    {
        $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';
        if ($action !== self::RECALC_ACTION) {
            return '';
        }

        // The page itself is readable with edit_posts, but this button rewrites
        // order + participant meta site-wide, so it needs a shop cap.
        if (!current_user_can('manage_woocommerce')) {
            return '';
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, self::RECALC_ACTION)) {
            // A stale tab (nonce older than 24 h) would otherwise look exactly
            // like a successful no-op run, so say what happened instead.
            return '<div class="notice notice-error"><p>' . esc_html__('The link has expired, please reload the page and try again.', 'alttag-registrations') . '</p></div>';
        }

        $report = GroupSeating::reconcile();

        $checked = 0;
        $restored = 0;
        $groups_fixed = 0;
        $waves_fixed = 0;
        $participants = 0;
        $unresolved = [];
        foreach ($report as $row) {
            $checked += (int) $row['checked'];
            $restored += (int) $row['parties_restored'];
            $groups_fixed += (int) $row['groups_fixed'];
            $waves_fixed += (int) $row['waves_fixed'];
            $participants += (int) $row['participants_updated'];
            foreach ((array) $row['unresolved'] as $order_id => $reason) {
                $unresolved[(int) $order_id] = (string) $reason;
            }
        }

        $html = '<div class="notice notice-success"><p>' . esc_html(sprintf(
            /* translators: 1: orders checked, 2: groups fixed, 3: waves fixed, 4: participants updated, 5: parties restored */
            __('Groups recalculated: %1$d orders checked, %2$d groups fixed, %3$d waves fixed, %4$d participants updated, %5$d parties restored.', 'alttag-registrations'),
            $checked,
            $groups_fixed,
            $waves_fixed,
            $participants,
            $restored
        )) . '</p></div>';

        if (!$unresolved) {
            return $html;
        }

        $html .= '<div class="notice notice-warning"><p>'
            . esc_html__('Orders that could not be recalculated automatically:', 'alttag-registrations')
            . '</p><ul style="list-style:disc;margin-left:24px">';
        foreach ($unresolved as $order_id => $reason) {
            $html .= '<li>' . esc_html(sprintf(
                /* translators: 1: order number, 2: why the order could not be recalculated */
                __('Order #%1$d: %2$s', 'alttag-registrations'),
                $order_id,
                $reason
            )) . '</li>';
        }
        $html .= '</ul></div>';

        return $html;
    }

    // ------------------------------------------------- display-only boost

    /**
     * Artificial seats per group, configured in the capacity overview.
     *
     * Stored as [product_id => ["wave|group" => int]] in one option. The admin
     * and customer-facing availability displays read it; checkout enforcement,
     * exports, check-in and e-mails always work with the real ledger.
     *
     * @return array<string, int> Map of "wave|group" to artificial seats.
     */
    private function fakeSeats(int $product_id): array
    {
        $all = get_option(self::FAKE_OPTION, []);
        $map = is_array($all) && isset($all[$product_id]) && is_array($all[$product_id]) ? $all[$product_id] : [];

        $out = [];
        foreach ($map as $key => $value) {
            if ((int) $value > 0) {
                $out[(string) $key] = (int) $value;
            }
        }

        return $out;
    }

    /**
     * Handle the per-product save button. Runs before any output so the
     * tables below already show the saved numbers. Returns the notice.
     */
    private function maybeSaveFakeSeats(): string
    {
        if (!isset($_POST[self::FAKE_PRODUCT])) {
            return '';
        }

        $product_id = (int) wp_unslash($_POST[self::FAKE_PRODUCT]);
        if ($product_id <= 0 || !current_user_can('manage_woocommerce')) {
            return '';
        }

        $nonce = isset($_POST[self::FAKE_NONCE]) ? sanitize_text_field(wp_unslash($_POST[self::FAKE_NONCE])) : '';
        if (!wp_verify_nonce($nonce, 'alttag_seating_fake')) {
            return '<div class="notice notice-error"><p>' . esc_html__('The link has expired, please reload the page and try again.', 'alttag-registrations') . '</p></div>';
        }

        $input = isset($_POST[self::FAKE_INPUT]) && is_array($_POST[self::FAKE_INPUT]) ? wp_unslash($_POST[self::FAKE_INPUT]) : [];

        // Only keys that match a real wave and group of this product are kept;
        // anything else a forged request might add is silently dropped.
        $valid = [];
        foreach (GroupSeating::waves($product_id) as $time => $wave) {
            foreach (GroupSeating::groups($product_id, (string) $time) as $group) {
                $valid[$time . '|' . $group] = true;
            }
        }

        $out = [];
        $saved = 0;
        foreach ($input as $key => $value) {
            $key = (string) $key;
            if (!isset($valid[$key])) {
                continue;
            }
            $value = absint($value);
            if ($value > 0) {
                $out[$key] = $value;
                $saved += $value;
            }
        }

        $all = get_option(self::FAKE_OPTION, []);
        if (!is_array($all)) {
            $all = [];
        }
        if ($out) {
            $all[$product_id] = $out;
        } else {
            unset($all[$product_id]);
        }
        update_option(self::FAKE_OPTION, $all, false);

        return '<div class="notice notice-success"><p>' . esc_html(sprintf(
            /* translators: %d: artificial seats saved */
            __('Artificial increase saved (+%d).', 'alttag-registrations'),
            $saved
        )) . '</p></div>';
    }

    // ------------------------------------------------------------ PDF ticket

    /**
     * {seating_wave} shortcode for the ticket designer.
     *
     * The station (A/B/C) is NOT exposed here on purpose: it can be changed in
     * the admin, so a printed ticket would show a stale one. The wave is what the
     * customer picked and it does not move.
     */
    public function addWaveShortcodes($replacements, $participant_id = 0, $product_id = 0)
    {
        if (!is_array($replacements)) {
            return $replacements;
        }

        $wave = (string) get_post_meta((int) $participant_id, GroupSeating::META_TIME, true);
        $replacements['{seating_wave}'] = $wave;

        return $replacements;
    }

    /** @return int[] */
    private function seatingProducts(): array
    {
        return GroupSeating::enabledProducts();
    }
}
