<?php

namespace Alttag\Registrations\Module;

use Alttag\Registrations\FieldBuilder;
use Alttag\Registrations\ParticipantState;
use Alttag\Registrations\RegistrationContext;
use function Alttag\Registrations\ctx;
use function Alttag\Registrations\is_event_multi_day;
use function Alttag\Registrations\get_participant_days_data;
use function Alttag\Registrations\get_participant_available_date_labels;
use function Alttag\Registrations\get_total_persons_from_days_data;
use function Alttag\Registrations\format_days_data_with_labels;
use function Alttag\Registrations\format_days_with_labels;

if (!defined('ABSPATH')) {
    exit;
}

class MultiDayModule extends AbstractModule
{
    public function getId(): string
    {
        return 'extended_participant_features';
    }

    public function getName(): string
    {
        return __('Multi-day Registrations', 'alttag-registrations');
    }

    public function getDescription(): string
    {
        return __('Extended participant features for multi-day events with per-day tracking and verification.', 'alttag-registrations');
    }

    public function getSettingsTab(): ?string
    {
        return 'participants';
    }

    public function isEnabled(): bool
    {
        $setting = parent::isEnabled();
        return apply_filters('alttag_registrations_enable_extended_participant_features', $setting);
    }

    public function getSettingsFields(): array
    {
        return [
            'enable_multi_day_participant_features' => [
                'label' => __('Enable Multi-Day Features', 'alttag-registrations'),
                'type' => 'checkbox',
                'description' => __('Enable selected-days columns, per-day verification tools and multi-day participant admin helpers', 'alttag-registrations'),
            ],
            'enable_multi_day_duplicate_validation' => [
                'label' => __('Enable Multi-Day Duplicate Validation', 'alttag-registrations'),
                'type' => 'checkbox',
                'description' => __('Block overlapping day registrations for the same email on equivalent products', 'alttag-registrations'),
            ],
        ];
    }

    public function registerHooks(): void
    {
        add_filter('alttag_registrations_participant_columns', [$this, 'addParticipantColumns'], 10);
        add_filter('alttag_registrations_participant_column_content', [$this, 'renderParticipantColumn'], 10, 3);
        add_action('alttag_registrations_participant_before_filters', [$this, 'renderLivestreamFilter']);
        add_action('alttag_registrations_participant_after_filters', [$this, 'renderProductFilter']);
        add_filter('alttag_registrations_participant_filters_query', [$this, 'applyParticipantFilters'], 10);
        add_filter('alttag_registrations_participant_filter_arg_names', [$this, 'extendFilterArgNames']);

        add_filter('alttag_registrations_export_field_order', [$this, 'extendExportFieldOrder']);
        add_filter('alttag_registrations_export_fields', [$this, 'extendExportFields'], 10, 2);
        add_filter('alttag_registrations_export_cell_value', [$this, 'formatExportCellValue'], 10, 4);

        add_filter('alttag_registrations_import_preview_columns', [$this, 'extendImportPreviewColumns']);
        add_filter('alttag_registrations_import_preview_cell', [$this, 'formatImportPreviewCell'], 10, 4);

        add_filter('alttag_registrations_meta_box_sections', [$this, 'extendMetaBoxSections'], 10, 2);
        add_filter('alttag_registrations_meta_fields', [$this, 'extendMetaFields']);
        add_action('alttag_registrations_participant_before_meta_box_field_value', [$this, 'renderSelectedDaysMetaValue'], 10, 3);
        add_action('add_meta_boxes_participant', [$this, 'addPersonsPerDayMetaBox']);
        add_action('save_post_participant', [$this, 'savePersonsPerDay'], 20, 2);

        add_action('alttag_registrations_email_after_custom_fields', [$this, 'renderSelectedDaysEmailBlock'], 10, 2);
        add_action('alttag_registrations_verification_after_section_content', [$this, 'renderVerificationStatusTable'], 10, 3);

        // Enrich livestream webhook with attendance dates
        add_filter('alttag_registrations_livestream_webhook_body', [$this, 'enrichWebhookWithDays'], 10, 2);
    }

    private function isMultiDayEnabled()
    {
        return apply_filters('alttag_registrations_enable_multi_day_participant_features', is_event_multi_day());
    }

    // =========================================================================
    // Columns
    // =========================================================================

    public function addParticipantColumns($columns)
    {
        $new_columns = [];
        foreach ($columns as $key => $label) {
            if ($key === 'actions') {
                $new_columns['product_name'] = __('Product', 'alttag-registrations');
                if ($this->isMultiDayEnabled()) {
                    $new_columns['selected_days'] = __('Selected days', 'alttag-registrations');
                }
            }
            $new_columns[$key] = $label;
        }
        return $new_columns;
    }

    public function renderParticipantColumn($content, $column, $post_id)
    {
        $state = ParticipantState::get($post_id);
        if (!$state) {
            return $content;
        }

        if ($column === 'product_name') {
            $name = $state->productName();
            return $name !== '' ? esc_html($name) : '<span style="color: #999;">-</span>';
        }

        if ($column === 'selected_days' && $this->isMultiDayEnabled()) {
            $summary = ctx()->withParticipant($post_id)->selectedDaysSummary();
            return $summary !== '' ? esc_html($summary) : '<span style="color: #999;">-</span>';
        }

        if ($column === 'registration_status' && $this->isMultiDayEnabled()) {
            $selected_days = $state->selectedDays();
            $registered_dates = $state->registeredDates();

            if (ctx()->withParticipant($post_id)->isLivestreamParticipant()) {
                // Livestream users don't do per-day check-in — leave the cell
                // empty by default. Hosts that also mark online tickets attended
                // on site can opt in (filter → false) to a union breakdown of
                // planned + attended days.
                if (apply_filters('alttag_registrations_hide_livestream_status_days', true, $post_id)) {
                    return '<span style="color: #999;">-</span>';
                }
                $days = $selected_days;
                foreach ($registered_dates as $d) {
                    if (!in_array($d, $days, true)) {
                        $days[] = $d;
                    }
                }
                sort($days);
                if (empty($days)) {
                    return '<span style="color: #999;">-</span>';
                }
            } else {
                $days = $selected_days;
                if (empty($days)) {
                    return $content;
                }
            }

            $available_dates = ctx()->withParticipant($post_id)->availableDates();
            $verified_data = $state->verifiedDaysData();
            $parts = [];

            foreach ($days as $date) {
                $label = $available_dates[$date] ?? $date;
                $total_persons = $state->personCountForDate($date);
                $verified_persons = isset($verified_data[$date]) ? (int) $verified_data[$date] : 0;
                $persons_info = $total_persons > 1 ? ' (' . $verified_persons . '/' . $total_persons . ')' : '';

                if (in_array($date, $registered_dates, true)) {
                    $parts[] = '<span style="color: green;">OK ' . esc_html($label) . $persons_info . '</span>';
                } else {
                    $parts[] = '<span style="color: #999;">- ' . esc_html($label) . $persons_info . '</span>';
                }
            }

            return !empty($parts) ? implode('<br>', $parts) : $content;
        }

        return $content;
    }

    // =========================================================================
    // Filters
    // =========================================================================

    public function renderLivestreamFilter()
    {
        if (!RegistrationContext::current()->isLivestreamEnabled()) {
            return;
        }

        $current = isset($_GET['is_livestream_user']) ? sanitize_text_field($_GET['is_livestream_user']) : '';
        echo '<select name="is_livestream_user">';
        echo '<option value="">' . esc_html__('All participants', 'alttag-registrations') . '</option>';
        echo '<option value="1"' . selected($current, '1', false) . '>' . esc_html__('Livestream users', 'alttag-registrations') . '</option>';
        echo '<option value="0"' . selected($current, '0', false) . '>' . esc_html__('In-person participants', 'alttag-registrations') . '</option>';
        echo '</select>';
    }

    public function renderProductFilter()
    {
        // Product dropdown lives in AdminUI::addParticipantFilters (uses
        // `participant_product` post-ID keying) — we only render the
        // date-based dropdowns here to avoid a duplicate "All products"
        // select on the participants list.
        $dates = $this->collectParticipantDates();
        if (empty($dates)) {
            return;
        }

        $selected_current = isset($_GET['filter_selected_day'])
            ? sanitize_text_field($_GET['filter_selected_day']) : '';
        echo '<select name="filter_selected_day">';
        echo '<option value="">' . esc_html__('Planned for any day', 'alttag-registrations') . '</option>';
        foreach ($dates as $date) {
            $label = function_exists('Alttag\\Registrations\\format_date')
                ? \Alttag\Registrations\format_date($date) : $date;
            echo '<option value="' . esc_attr($date) . '"'
                . selected($selected_current, $date, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';

        $registered_current = isset($_GET['filter_registered_day'])
            ? sanitize_text_field($_GET['filter_registered_day']) : '';
        echo '<select name="filter_registered_day">';
        echo '<option value="">' . esc_html__('Attended on any day', 'alttag-registrations') . '</option>';
        foreach ($dates as $date) {
            $label = function_exists('Alttag\\Registrations\\format_date')
                ? \Alttag\Registrations\format_date($date) : $date;
            echo '<option value="' . esc_attr($date) . '"'
                . selected($registered_current, $date, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    /**
     * Collect distinct YYYY-MM-DD dates referenced by any participant's
     * selected_days / registered_dates meta. Cheap enough for typical event
     * sizes; runs once per admin filter render.
     */
    private function collectParticipantDates(): array
    {
        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT meta_value
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'participant'
               AND p.post_status != 'trash'
               AND pm.meta_key IN ('selected_days', 'registered_dates')
               AND pm.meta_value LIKE 'a:%'"
        );
        $dates = [];
        foreach ($rows as $serialized) {
            $values = maybe_unserialize($serialized);
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as $d) {
                if (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                    $dates[$d] = true;
                }
            }
        }
        $dates = array_keys($dates);
        sort($dates);
        return $dates;
    }

    public function applyParticipantFilters($meta_query)
    {
        if (!is_array($meta_query)) {
            $meta_query = [];
        }

        if (RegistrationContext::current()->isLivestreamEnabled() && isset($_GET['is_livestream_user']) && $_GET['is_livestream_user'] !== '') {
            if (sanitize_text_field($_GET['is_livestream_user']) === '1') {
                $meta_query[] = ['key' => 'is_livestream_user', 'value' => '1'];
            } else {
                $meta_query[] = [
                    'relation' => 'OR',
                    ['key' => 'is_livestream_user', 'value' => '1', 'compare' => '!='],
                    ['key' => 'is_livestream_user', 'compare' => 'NOT EXISTS'],
                ];
            }
        }

        if (isset($_GET['product_sku']) && $_GET['product_sku'] !== '') {
            global $wpdb;
            $sku = sanitize_text_field($_GET['product_sku']);
            $product_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s",
                $sku
            ));
            $meta_query[] = [
                'key' => 'product_id',
                'value' => !empty($product_ids) ? array_map('strval', $product_ids) : ['0'],
                'compare' => 'IN',
            ];
        }

        // Filter by planned attendance day — match a serialized date inside
        // the participant's selected_days array (stored as a PHP-serialized
        // array meta).
        if (!empty($_GET['filter_selected_day'])) {
            $date = sanitize_text_field((string) $_GET['filter_selected_day']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $meta_query[] = [
                    'key' => 'selected_days',
                    'value' => 's:' . strlen($date) . ':"' . $date . '"',
                    'compare' => 'LIKE',
                ];
            }
        }

        // Filter by confirmed attendance day (registered via the verify page).
        if (!empty($_GET['filter_registered_day'])) {
            $date = sanitize_text_field((string) $_GET['filter_registered_day']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $meta_query[] = [
                    'key' => 'registered_dates',
                    'value' => 's:' . strlen($date) . ':"' . $date . '"',
                    'compare' => 'LIKE',
                ];
            }
        }

        return $meta_query;
    }

    public function extendFilterArgNames($names)
    {
        if (!is_array($names)) {
            $names = [];
        }
        foreach (['is_livestream_user', 'product_sku', 'filter_selected_day', 'filter_registered_day'] as $key) {
            if (!in_array($key, $names, true)) {
                $names[] = $key;
            }
        }
        return $names;
    }

    // =========================================================================
    // Export / Import
    // =========================================================================

    public function extendExportFieldOrder($fields)
    {
        $extra = ['product_name', 'product_sku'];
        if ($this->isMultiDayEnabled()) {
            $extra = array_merge($extra, ['selected_days', 'total_persons', 'registered_dates', 'verified_persons']);
        }
        foreach ($extra as $field) {
            if (!in_array($field, $fields, true)) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    public function extendExportFields($export_fields, $meta_fields)
    {
        $export_fields['product_name'] = __('Product', 'alttag-registrations');
        $export_fields['product_sku'] = 'SKU';
        if ($this->isMultiDayEnabled()) {
            $export_fields['selected_days'] = __('Selected days', 'alttag-registrations');
            $export_fields['total_persons'] = __('Total persons', 'alttag-registrations');
            $export_fields['registered_dates'] = __('Registered days', 'alttag-registrations');
            $export_fields['verified_persons'] = __('Verified persons', 'alttag-registrations');
        }
        return $export_fields;
    }

    public function formatExportCellValue($value, $key, $participant, $participant_id)
    {
        $state = ParticipantState::get($participant_id);
        if (!$state) {
            return $value;
        }

        if ($key === 'product_sku') {
            return $state->productSku();
        }

        if (!$this->isMultiDayEnabled()) {
            return $value;
        }

        if ($key === 'selected_days') {
            return ctx()->withParticipant($participant_id)->selectedDaysSummary();
        }
        if ($key === 'total_persons') {
            $days_data = $state->selectedDaysData();
            return !empty($days_data) ? (string) get_total_persons_from_days_data($days_data) : '1';
        }
        if ($key === 'registered_dates') {
            $days = $state->registeredDates();
            return !empty($days) ? ctx()->withParticipant($participant_id)->formatSelectedDays($days) : '';
        }
        if ($key === 'verified_persons') {
            $verified = $state->verifiedDaysData();
            return !empty($verified) ? (string) array_sum($verified) : '0';
        }

        return $value;
    }

    public function extendImportPreviewColumns($columns)
    {
        $new_columns = [];
        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;
            if ($key === 'company_name') {
                $new_columns['street'] = __('Street', 'alttag-registrations');
                $new_columns['city'] = __('City', 'alttag-registrations');
                $new_columns['zip'] = __('ZIP', 'alttag-registrations');
                $new_columns['country'] = __('Country', 'alttag-registrations');
                if (RegistrationContext::current()->isLivestreamEnabled()) {
                    $new_columns['is_livestream_user'] = __('Livestream', 'alttag-registrations');
                }
            }
        }
        return $new_columns;
    }

    public function formatImportPreviewCell($html, $key, $value, $participant = [])
    {
        if ($key === 'is_livestream_user') {
            return $value === '1' ? 'OK' : '-';
        }
        return $html;
    }

    // =========================================================================
    // Meta Box
    // =========================================================================

    /**
     * Does this participant have days of its own?
     *
     * Strictly the multi-day selection, no session fallback: this decides whether
     * a "Selected days" row has anything to show, and a lone session date is
     * already shown by the session fields.
     *
     * @param int $participant_id
     * @return bool
     */
    private function hasOwnDays($participant_id): bool
    {
        if (!empty(get_participant_days_data($participant_id))) {
            return true;
        }

        $state = ParticipantState::get($participant_id);

        return $state && !empty($state->selectedDays());
    }

    public function extendMetaBoxSections($sections, $participant_id = 0)
    {
        if (!is_array($sections)) {
            $sections = [];
        }
        if (!isset($sections['personal']['fields']) || !is_array($sections['personal']['fields'])) {
            $sections['personal']['fields'] = [];
        }

        // No product_name here, the Registration Details section already has it.
        $extra = FieldBuilder::getMetaBoxSectionFields();

        // Only when this participant actually has days. The module can be on for
        // the site while a given product is sold as a single time slot, and an
        // empty "Selected days" row on those is just noise.
        if ($this->isMultiDayEnabled()
            && (!$participant_id || $this->hasOwnDays((int) $participant_id))) {
            $extra[] = 'selected_days';
        }
        $sections['personal']['fields'] = array_values(array_unique(array_merge($sections['personal']['fields'], $extra)));
        return $sections;
    }

    public function extendMetaFields($fields)
    {
        $fields['product_name'] = [
            'label' => __('Product', 'alttag-registrations'),
            'type' => 'text',
            'readonly' => true,
        ];
        if ($this->isMultiDayEnabled()) {
            $fields['selected_days'] = [
                'label' => __('Selected days', 'alttag-registrations'),
                'type' => 'text',
                'readonly' => true,
            ];
        }
        return $fields;
    }

    public function renderSelectedDaysMetaValue($field, $value, $post_id)
    {
        if (!$this->isMultiDayEnabled() || !is_array($value) || empty($value)) {
            return;
        }

        $first = reset($value);
        if (!is_string($first) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $first)) {
            return;
        }

        $days_data = get_participant_days_data($post_id);
        $date_labels = get_participant_available_date_labels($post_id);

        if (!empty($days_data)) {
            echo esc_html(format_days_data_with_labels($days_data, $date_labels));
        } else {
            echo esc_html(format_days_with_labels($value, $date_labels));
        }
        echo '<style>#selected_days { display: none; }</style>';
    }

    /**
     * Dates this participant can be checked in on.
     *
     * Multi-day selection first, then the chosen session date. Products sold as a
     * time slot rather than a multi-day pass only ever have the session, and
     * without this fallback their attendance box had nothing to list.
     *
     * @param int $participant_id
     * @return string[] Y-m-d
     */
    private function attendanceDates($participant_id): array
    {
        $participant_id = (int) $participant_id;

        $days_data = get_participant_days_data($participant_id);
        if (!empty($days_data)) {
            return array_keys($days_data);
        }

        $state = ParticipantState::get($participant_id);
        $selected = $state ? $state->selectedDays() : [];
        if (!empty($selected)) {
            return array_values((array) $selected);
        }

        $session = get_post_meta($participant_id, 'selected_session', true);
        $session = is_array($session) ? $session : ($session !== '' ? [$session] : []);

        $dates = [];
        foreach ($session as $date) {
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    public function addPersonsPerDayMetaBox($post = null)
    {
        if (!$this->isMultiDayEnabled()) {
            return;
        }

        // Nothing to list means no box at all, an empty one just confuses.
        if ($post instanceof \WP_Post && !$this->attendanceDates($post->ID)) {
            return;
        }

        // A booking sold per participant type is counted in people, not in days,
        // and ParticipantTypeModule has its own check-in box for it.
        if ($post instanceof \WP_Post) {
            $state = ParticipantState::get($post->ID);
            $types = $state ? $state->getMeta('selected_participant_types_data') : [];
            if (is_array($types) && array_filter($types)) {
                return;
            }
        }

        add_meta_box(
            'persons_per_day',
            __('Persons per day', 'alttag-registrations'),
            [$this, 'renderPersonsPerDayMetaBox'],
            'participant',
            'normal',
            'high'
        );
    }

    /**
     * Whether the "Persons per day" metabox exposes editable on-site attendance
     * checkboxes (plus the matching save handler). Default OFF — the metabox is
     * a read-only verified/count display. Hosts opt in via the filter.
     */
    private function personsPerDayEditEnabled()
    {
        return apply_filters('alttag_registrations_enable_persons_per_day_edit', false);
    }

    public function renderPersonsPerDayMetaBox($post)
    {
        if ($this->personsPerDayEditEnabled()) {
            $this->renderPersonsPerDayEditable($post);
            return;
        }

        // Default: read-only verified/count breakdown.
        $days_data = get_participant_days_data($post->ID);
        if (empty($days_data)) {
            // Session-only ticket: one row for the session date, one person.
            foreach ($this->attendanceDates($post->ID) as $date) {
                $days_data[$date] = 1;
            }
        }
        if (empty($days_data)) {
            return;
        }

        $date_labels = get_participant_available_date_labels($post->ID);
        $state = ParticipantState::get($post->ID);
        $verified_data = $state ? $state->verifiedDaysData() : [];
        $total_all = get_total_persons_from_days_data($days_data);
        $verified_all = is_array($verified_data) ? array_sum($verified_data) : 0;

        echo '<table class="form-table">';
        foreach ($days_data as $date => $count) {
            $label = $date_labels[$date] ?? $date;
            $verified = isset($verified_data[$date]) ? (int) $verified_data[$date] : 0;
            $count = (int) $count;
            $color = $verified >= $count ? 'green' : ($verified > 0 ? 'orange' : '#999');
            echo '<tr><th>' . esc_html($label) . '</th>';
            echo '<td><span style="color:' . $color . '; font-weight: 600;">' . $verified . ' / ' . $count . '</span></td></tr>';
        }
        if ($total_all > 1) {
            echo '<tr style="border-top: 2px solid #ddd;"><th><strong>' . esc_html__('Total', 'alttag-registrations') . '</strong></th>';
            echo '<td><strong>' . $verified_all . ' / ' . $total_all . '</strong></td></tr>';
        }
        echo '</table>';
    }

    /**
     * Editable variant of the "Persons per day" metabox: an "Attended in person"
     * checkbox per day, saved by savePersonsPerDay(). Gated by
     * personsPerDayEditEnabled().
     */
    private function renderPersonsPerDayEditable($post)
    {
        $days_data = get_participant_days_data($post->ID);
        $state = ParticipantState::get($post->ID);
        $dates = $this->attendanceDates($post->ID);
        if (empty($dates)) {
            return;
        }

        $date_labels = get_participant_available_date_labels($post->ID);
        $registered_dates = $state ? $state->registeredDates() : [];
        $verified_data = $state ? $state->verifiedDaysData() : [];

        wp_nonce_field('save_persons_per_day', 'persons_per_day_nonce');
        echo '<p class="description">' . esc_html__('Mark the days this person attended on site (works for online/livestream tickets too).', 'alttag-registrations') . '</p>';
        echo '<table class="form-table">';
        foreach ($dates as $date) {
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $label = $date_labels[$date] ?? $date;
            $count = isset($days_data[$date]) ? (int) $days_data[$date] : 1;
            $verified = isset($verified_data[$date]) ? (int) $verified_data[$date] : 0;
            $count_info = $count > 1 ? ' <span style="color:#666; font-weight: 400;">(' . $verified . ' / ' . $count . ')</span>' : '';
            echo '<tr><th>' . esc_html($label) . $count_info . '</th>';
            echo '<td><label><input type="checkbox" name="persons_per_day_attended[]" value="' . esc_attr($date) . '"'
                . checked(in_array($date, $registered_dates, true), true, false) . '> '
                . esc_html__('Attended in person', 'alttag-registrations') . '</label></td></tr>';
        }
        echo '</table>';
    }

    /**
     * Save on-site attendance ticked in the "Persons per day" metabox.
     * Sets registered_dates + verified_days_data and mirrors the status logic
     * used by VerificationManager. Does not touch product_id or selected_days.
     * No-op unless the editable metabox is enabled and its nonce is present.
     */
    public function savePersonsPerDay($post_id, $post)
    {
        if (!$this->personsPerDayEditEnabled()) {
            return;
        }
        if (!$this->isMultiDayEnabled()) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!isset($_POST['persons_per_day_nonce'])
            || !wp_verify_nonce($_POST['persons_per_day_nonce'], 'save_persons_per_day')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $state = ParticipantState::get($post_id);
        if (!$state) {
            return;
        }
        $state->reload();

        $days_data = $state->selectedDaysData();

        // The same source the checkboxes were drawn from, otherwise a session-only
        // ticket would render a box whose input is then silently discarded here.
        $valid_dates = $this->attendanceDates($post_id);
        $selected_days = $state->selectedDays();
        if (empty($selected_days)) {
            // Session-only ticket: the session date is the one day to attend.
            $selected_days = $valid_dates;
        }

        $submitted = isset($_POST['persons_per_day_attended']) && is_array($_POST['persons_per_day_attended'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['persons_per_day_attended']))
            : [];

        $new_registered = [];
        foreach ($submitted as $date) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && in_array($date, $valid_dates, true)) {
                $new_registered[] = $date;
            }
        }
        $new_registered = array_values(array_unique($new_registered));
        sort($new_registered);

        $old_registered = $state->registeredDates();
        $old_sorted = $old_registered;
        sort($old_sorted);
        if ($new_registered === $old_sorted) {
            return;
        }

        $added = array_values(array_diff($new_registered, $old_registered));
        $removed = array_values(array_diff($old_registered, $new_registered));

        $verified_data = [];
        foreach ($new_registered as $date) {
            $count = isset($days_data[$date]) ? (int) $days_data[$date] : 1;
            $verified_data[$date] = max(1, $count);
        }

        $state->setMeta('registered_dates', $new_registered);
        $state->setMeta('verified_days_data', $verified_data);

        if (empty($new_registered)) {
            if ($state->registrationStatus() !== 'pending') {
                $state->setMeta('registration_status', 'cancelled');
            }
        } else {
            $selected_count = count($selected_days);
            $state->setMeta(
                'registration_status',
                $selected_count > 0 && count($new_registered) >= $selected_count ? 'completed' : 'partial'
            );
        }

        $date_labels = get_participant_available_date_labels($post_id);
        $fmt = function ($dates) use ($date_labels) {
            return implode(', ', array_map(function ($d) use ($date_labels) {
                return $date_labels[$d] ?? $d;
            }, $dates));
        };
        $parts = [];
        if (!empty($added)) {
            $parts[] = sprintf(__('added %s', 'alttag-registrations'), $fmt($added));
        }
        if (!empty($removed)) {
            $parts[] = sprintf(__('removed %s', 'alttag-registrations'), $fmt($removed));
        }
        $current_user = wp_get_current_user();
        $state->addToHistory(sprintf(
            __('On-site attendance updated (%s) by %s', 'alttag-registrations'),
            implode('; ', $parts),
            $current_user->user_email
        ));
    }

    // =========================================================================
    // Email & Verification
    // =========================================================================

    public function renderSelectedDaysEmailBlock($order, $participant_id = null)
    {
        if (!$this->isMultiDayEnabled()) {
            return;
        }

        $context = $participant_id ? ctx()->withParticipant($participant_id) : ctx()->withOrder($order);
        $summary = $context->selectedDaysSummary();
        $summary = apply_filters(
            'alttag_registrations_email_selected_days_summary',
            $summary,
            $order,
            $participant_id
        );
        if ($summary === '') {
            return;
        }

        $style = 'width: 100%; box-sizing: border-box; margin: 0 0 10px 0; color: ' . ctx()->primaryColor()
            . '; font-size: 14px; font-family: Arial, Helvetica, sans-serif; line-height: 1.6;';
        echo '<div style="' . esc_attr($style) . '">' . sprintf(
            __('Selected days: %s', 'alttag-registrations'),
            esc_html($summary)
        ) . '</div>';
    }

    public function renderVerificationStatusTable($section_id, $section, $customer_data)
    {
        if (!$this->isMultiDayEnabled() || $section_id !== 'status') {
            return;
        }

        $participant_id = $customer_data['id'] ?? null;
        $state = $participant_id ? ParticipantState::get($participant_id) : null;
        if (!$state) {
            return;
        }

        // Livestream users don't do per-day check-in — skip the table by
        // default. Hosts that check online tickets in on site can opt in
        // (filter → false) to render the per-day table for them too.
        if (ctx()->withParticipant($participant_id)->isLivestreamParticipant()
            && apply_filters('alttag_registrations_hide_livestream_verification_table', true, $participant_id)) {
            return;
        }

        $selected_days = $state->selectedDays();
        if (empty($selected_days)) {
            return;
        }

        // When participant types are active for this product, the participant-type
        // table owns check-in. Per-day check-in would duplicate the same action.
        $product_id = (int) $state->getMeta('product_id');
        if ($product_id > 0) {
            $types_meta = get_post_meta($product_id, '_participant_types', true);
            if (is_array($types_meta) && !empty($types_meta)) {
                return;
            }
        }

        $available_dates = ctx()->withParticipant($participant_id)->availableDates();
        $registered_dates = isset($customer_data['registered_dates']) && is_array($customer_data['registered_dates'])
            ? $customer_data['registered_dates'] : [];
        $verified_data = $state->verifiedDaysData();
        $total_all = 0;
        $verified_all = is_array($verified_data) ? array_sum($verified_data) : 0;

        echo '<div style="margin-top: 20px;">';
        echo '<h4>' . esc_html__('Registration per day', 'alttag-registrations') . ':</h4>';
        echo '<table style="width: 100%; border-collapse: collapse; margin-top: 10px;">';
        echo '<tr>';
        echo '<th style="text-align: left; padding: 8px; border-bottom: 2px solid #ddd;">' . esc_html__('Day', 'alttag-registrations') . '</th>';
        echo '<th style="text-align: left; padding: 8px; border-bottom: 2px solid #ddd;">' . esc_html__('Persons', 'alttag-registrations') . '</th>';
        echo '<th style="text-align: left; padding: 8px; border-bottom: 2px solid #ddd;">' . esc_html__('Status', 'alttag-registrations') . '</th>';
        echo '<th style="text-align: left; padding: 8px; border-bottom: 2px solid #ddd;">' . esc_html__('Action', 'alttag-registrations') . '</th>';
        echo '</tr>';

        foreach ($selected_days as $date) {
            $label = $available_dates[$date] ?? $date;
            $is_registered = in_array($date, $registered_dates, true);
            $total_persons = $state->personCountForDate($date);
            $verified_persons = isset($verified_data[$date]) ? (int) $verified_data[$date] : 0;
            $total_all += $total_persons;

            echo '<tr>';
            echo '<td style="padding: 8px; border-bottom: 1px solid #eee;">' . esc_html($label) . '</td>';
            if ($total_persons > 1) {
                $color = $verified_persons >= $total_persons ? 'green' : ($verified_persons > 0 ? 'orange' : '#999');
                echo '<td style="padding: 8px; border-bottom: 1px solid #eee;"><span style="color: ' . esc_attr($color) . '; font-weight: 600;">' . $verified_persons . '/' . $total_persons . '</span></td>';
            } else {
                echo '<td style="padding: 8px; border-bottom: 1px solid #eee;">1</td>';
            }

            if ($is_registered) {
                echo '<td style="padding: 8px; border-bottom: 1px solid #eee;"><span style="color: green;">OK ' . esc_html__('Registered', 'alttag-registrations') . '</span></td>';
            } else {
                echo '<td style="padding: 8px; border-bottom: 1px solid #eee;"><span style="color: orange;">- ' . esc_html__('Pending', 'alttag-registrations') . '</span></td>';
            }

            echo '<td style="padding: 8px; border-bottom: 1px solid #eee;"><div class="verify-actions">';
            $vs = esc_attr($customer_data['variable_symbol']);
            $d = esc_attr($date);

            if ($total_persons > 1) {
                // +1 person
                if ($verified_persons < $total_persons) {
                    echo '<form method="post">';
                    wp_nonce_field('registration_action', 'registration_nonce');
                    echo '<input type="hidden" name="variable_symbol" value="' . $vs . '">';
                    echo '<button type="submit" name="action" value="checkin_person_' . $d . '" class="verify-btn verify-btn--register verify-btn--sm" title="' . esc_attr__('Add one person', 'alttag-registrations') . '">+1</button>';
                    echo '</form>';
                }
                // -1 person
                if ($verified_persons > 0) {
                    echo '<form method="post">';
                    wp_nonce_field('registration_action', 'registration_nonce');
                    echo '<input type="hidden" name="variable_symbol" value="' . $vs . '">';
                    echo '<button type="submit" name="action" value="checkout_person_' . $d . '" class="verify-btn verify-btn--cancel verify-btn--sm" title="' . esc_attr__('Remove one person', 'alttag-registrations') . '">-1</button>';
                    echo '</form>';
                }
                // Register all persons for this day
                if ($verified_persons < $total_persons) {
                    echo '<form method="post">';
                    wp_nonce_field('registration_action', 'registration_nonce');
                    echo '<input type="hidden" name="variable_symbol" value="' . $vs . '">';
                    echo '<button type="submit" name="action" value="checkin_all_' . $d . '" class="verify-btn verify-btn--register verify-btn--sm">' . esc_html__('All', 'alttag-registrations') . '</button>';
                    echo '</form>';
                }
                // Cancel all persons for this day
                if ($verified_persons > 0) {
                    echo '<form method="post">';
                    wp_nonce_field('registration_action', 'registration_nonce');
                    echo '<input type="hidden" name="variable_symbol" value="' . $vs . '">';
                    echo '<button type="submit" name="action" value="checkout_all_' . $d . '" class="verify-btn verify-btn--cancel verify-btn--sm">' . esc_html__('Cancel All', 'alttag-registrations') . '</button>';
                    echo '</form>';
                }
            } else {
                // Single person — simple register/cancel
                echo '<form method="post">';
                wp_nonce_field('registration_action', 'registration_nonce');
                echo '<input type="hidden" name="variable_symbol" value="' . $vs . '">';
                if ($is_registered) {
                    echo '<button type="submit" name="action" value="cancel_date_' . $d . '" class="verify-btn verify-btn--cancel verify-btn--sm">' . esc_html__('Cancel', 'alttag-registrations') . '</button>';
                } else {
                    echo '<button type="submit" name="action" value="register_date_' . $d . '" class="verify-btn verify-btn--register verify-btn--sm">' . esc_html__('Register', 'alttag-registrations') . '</button>';
                }
                echo '</form>';
            }

            echo '</div></td></tr>';
        }

        $total_color = $total_all > 0 && $verified_all >= $total_all
            ? 'green'
            : ($verified_all > 0 ? 'orange' : '#999');
        echo '<tr style="font-weight: 600;">';
        echo '<td style="padding: 8px; border-top: 2px solid #ddd;">'
            . esc_html__('Total', 'alttag-registrations') . '</td>';
        echo '<td style="padding: 8px; border-top: 2px solid #ddd;">'
            . '<span style="color: ' . esc_attr($total_color) . ';">'
            . $verified_all . '/' . $total_all
            . '</span></td>';
        echo '<td colspan="2" style="padding: 8px; border-top: 2px solid #ddd;"></td>';
        echo '</tr>';

        echo '</table>';

        // "Register All" / "Cancel All" bulk buttons
        $pending_dates = array_diff($selected_days, $registered_dates);
        $registered_selected = array_intersect($selected_days, $registered_dates);

        if (!empty($pending_dates)) {
            echo '<form method="post" style="margin-top: 12px; display: inline;">';
            wp_nonce_field('registration_action', 'registration_nonce');
            echo '<input type="hidden" name="variable_symbol" value="' . esc_attr($customer_data['variable_symbol']) . '">';
            echo '<button type="submit" name="action" value="register_all" class="verify-btn verify-btn--register">' . esc_html__('Register All', 'alttag-registrations') . '</button>';
            echo '</form>';
        }

        if (!empty($registered_selected) && count($registered_selected) > 1) {
            echo '<form method="post" style="margin-top: 12px; display: inline; margin-left: 8px;">';
            wp_nonce_field('registration_action', 'registration_nonce');
            echo '<input type="hidden" name="variable_symbol" value="' . esc_attr($customer_data['variable_symbol']) . '">';
            echo '<button type="submit" name="action" value="cancel_all" class="verify-btn verify-btn--cancel">' . esc_html__('Cancel All', 'alttag-registrations') . '</button>';
            echo '</form>';
        }

        echo '</div>';
    }

    // =========================================================================
    // Webhook enrichment
    // =========================================================================

    public function enrichWebhookWithDays($body, $data)
    {
        $participant_id = $data['participant_id'] ?? null;
        if (!$participant_id) {
            return $body;
        }

        $selected_days = ctx()->withParticipant($participant_id)->selectedDays();
        if (!empty($selected_days)) {
            if (!isset($body['meta_data'])) {
                $body['meta_data'] = [];
            }
            $body['meta_data']['attendance_dates'] = $selected_days;
        }

        return $body;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function getProductsBySku()
    {
        global $wpdb;

        $default_lang = RegistrationContext::current()->hasPolylang() && function_exists('pll_default_language')
            ? pll_default_language('slug') : null;

        $results = $wpdb->get_results("
            SELECT p.ID, p.post_title, pm.meta_value AS sku
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            WHERE p.post_type = 'product' AND p.post_status = 'publish' AND pm.meta_value != ''
            ORDER BY p.post_title ASC
        ");

        $products = [];
        foreach ($results as $row) {
            if (isset($products[$row->sku])) {
                continue;
            }
            $lang = RegistrationContext::current()->hasPolylang() && function_exists('pll_get_post_language')
                ? pll_get_post_language($row->ID, 'slug') : null;
            if (!$default_lang || $lang === $default_lang || !isset($products[$row->sku])) {
                $products[$row->sku] = $row->post_title;
            }
        }

        return $products;
    }
}
