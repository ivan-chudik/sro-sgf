<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Auto-registers participant admin columns, filters, and export/import fields
 * based on FieldBuilder configuration.
 *
 * Each field in FieldBuilder can have these admin settings:
 *
 *   'admin_column' => [
 *       'enabled' => true,
 *       'position' => 'before_actions',  // before_actions, after_name, after_email
 *       'priority' => 10,
 *       'sortable' => true,
 *       'width' => null,                 // CSS width override
 *       'format' => null,                // 'boolean', 'date', 'link', null (plain text)
 *   ],
 *   'admin_filter' => [
 *       'enabled' => true,
 *       'type' => 'select',              // select, text
 *       'options' => [],                 // for select: ['value' => 'Label', ...]
 *       'label' => 'All types',          // placeholder/default option label
 *   ],
 *   'export' => [
 *       'enabled' => true,
 *       'priority' => 10,
 *       'header' => null,                // custom header, defaults to field label
 *       'format' => null,                // 'boolean', 'date', null
 *   ],
 *   'import' => [
 *       'enabled' => true,
 *       'header_aliases' => [],          // alternative column names for import matching
 *   ],
 */
class AdminColumnsManager
{
    public function registerHooks()
    {
        // Columns - insert FieldBuilder fields into participant list
        add_filter('alttag_registrations_participant_columns', [$this, 'addFieldColumns'], 5);
        add_filter('alttag_registrations_participant_column_content', [$this, 'renderFieldColumn'], 5, 3);
        add_filter('manage_edit-participant_sortable_columns', [$this, 'addSortableColumns'], 5);

        // Filters
        add_action('alttag_registrations_participant_before_filters', [$this, 'renderFieldFilters']);
        add_filter('alttag_registrations_participant_filters_query', [$this, 'applyFieldFilters'], 5);

        // Export
        add_filter('alttag_registrations_export_fields', [$this, 'addExportFields'], 5, 2);
        add_filter('alttag_registrations_export_cell_value', [$this, 'formatExportValue'], 5, 4);

        // Import
        add_filter('alttag_registrations_import_field_mapping', [$this, 'addImportMapping'], 5);
    }

    // =========================================================================
    // Columns
    // =========================================================================

    /**
     * Add columns from FieldBuilder definitions
     */
    public function addFieldColumns($columns)
    {
        $fields = FieldBuilder::getFields();
        $to_insert = [];

        // Only emit a column when at least one participant in the *current*
        // list-table view (publish vs. archived vs. all) actually has a value
        // for the field. Avoids visually-noisy empty columns left over from
        // a previous conference cycle, and keeps new fields hidden until they
        // are actually used. Scoped to the requested post_status so the
        // archived view and the active view stay independent.
        $populated_keys = $this->getPopulatedFieldKeysForCurrentView();

        foreach ($fields as $key => $field) {
            $col_config = $field['admin_column'] ?? null;
            if (!$col_config || empty($col_config['enabled'])) {
                continue;
            }

            // Skip fields already in default columns
            if (isset($columns[$key])) {
                continue;
            }

            // Hide field column when no participant in the visible scope has
            // a meaningful value. Bypass-able via `admin_column.always_show`
            // for fields the admin always wants to see even when empty.
            if (empty($col_config['always_show']) && !isset($populated_keys[$key])) {
                continue;
            }

            // Per-field admin column label override — used to keep list-table
            // headers short even when the checkout label has to be verbose
            // (e.g. social-evening / charity-run have multi-line consent text
            // on the form, but the list view just needs "Spoločenský večer").
            $column_label = $col_config['label'] ?? FieldBuilder::getFieldLabel($field);

            $to_insert[$key] = [
                'label' => $column_label,
                'position' => $col_config['position'] ?? 'before_actions',
                'priority' => $col_config['priority'] ?? 50,
            ];
        }

        if (empty($to_insert)) {
            return $columns;
        }

        // Sort by priority
        uasort($to_insert, function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });

        // Insert columns at specified positions
        $result = [];
        foreach ($columns as $col_key => $col_label) {
            // Insert "before_actions" columns
            if ($col_key === 'actions') {
                foreach ($to_insert as $field_key => $insert) {
                    if ($insert['position'] === 'before_actions') {
                        $result[$field_key] = $insert['label'];
                    }
                }
            }

            $result[$col_key] = $col_label;

            // Insert "after_name" columns
            if ($col_key === 'last_name') {
                foreach ($to_insert as $field_key => $insert) {
                    if ($insert['position'] === 'after_name') {
                        $result[$field_key] = $insert['label'];
                    }
                }
            }

            // Insert "after_email" columns
            if ($col_key === 'email') {
                foreach ($to_insert as $field_key => $insert) {
                    if ($insert['position'] === 'after_email') {
                        $result[$field_key] = $insert['label'];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Build the set of FieldBuilder meta keys that have at least one
     * "non-empty" value among participants in the currently-visible scope
     * (publish | archived | any other status the admin requested).
     *
     * "Non-empty" intentionally excludes '0' and 'no' so checkbox-style
     * fields where every participant explicitly opted out don't keep a
     * useless column visible.
     *
     * Cached per-request — `addFieldColumns()` is the only caller but the
     * filter can fire repeatedly for screen-options / hidden-columns logic.
     *
     * @return array<string,true> meta_key => true (lookup-friendly)
     */
    private function getPopulatedFieldKeysForCurrentView()
    {
        static $cache = null;
        if ($cache !== null && isset($cache[$this->currentViewKey()])) {
            return $cache[$this->currentViewKey()];
        }

        global $wpdb;

        $fields = FieldBuilder::getFields();
        $candidate_keys = [];
        foreach ($fields as $key => $field) {
            $col_config = $field['admin_column'] ?? null;
            if ($col_config && !empty($col_config['enabled'])) {
                $candidate_keys[] = $key;
            }
        }
        if (empty($candidate_keys)) {
            return $cache[$this->currentViewKey()] = [];
        }

        // Default "All" view in wp-admin/edit.php excludes archived/trash via
        // each status's `show_in_admin_all_list` flag (Archive is registered
        // with show_in_admin_all_list = false). Mirror that here so a column
        // doesn't appear in "All" just because some archived row has data.
        $status = $this->currentPostStatus();
        $status_sql = '';
        $params = [];
        if ($status !== null) {
            $status_sql = ' AND p.post_status = %s';
            $params[] = $status;
        } else {
            $status_sql = " AND p.post_status NOT IN ('archived', 'trash', 'auto-draft')";
        }

        $key_placeholders = implode(',', array_fill(0, count($candidate_keys), '%s'));
        $params = array_merge($params, $candidate_keys);

        $sql = $wpdb->prepare(
            "SELECT DISTINCT pm.meta_key
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'participant'
               $status_sql
               AND pm.meta_value IS NOT NULL
               AND pm.meta_value NOT IN ('', '0', 'no')
               AND pm.meta_key IN ($key_placeholders)",
            $params
        );

        $populated = $wpdb->get_col($sql);
        $result = [];
        foreach ($populated as $k) {
            $result[$k] = true;
        }
        $cache[$this->currentViewKey()] = $result;
        return $result;
    }

    /**
     * Selected post_status from the list-table URL, or null when the request
     * targets the "all" view (publish + most other statuses, excluding trash
     * and archived).
     */
    private function currentPostStatus()
    {
        $status = isset($_GET['post_status']) ? sanitize_text_field((string) $_GET['post_status']) : '';
        return $status !== '' ? $status : null;
    }

    private function currentViewKey()
    {
        return $this->currentPostStatus() ?? '__all__';
    }

    /**
     * Render column content from ParticipantState
     */
    public function renderFieldColumn($content, $column, $post_id)
    {
        // Only handle if content hasn't been set yet
        if ($content !== null) {
            return $content;
        }

        $field = FieldBuilder::getField($column);
        if (!$field) {
            return $content;
        }

        $col_config = $field['admin_column'] ?? null;
        if (!$col_config || empty($col_config['enabled'])) {
            return $content;
        }

        $state = ParticipantState::get($post_id);
        if (!$state) {
            return '';
        }

        $value = $state->getMeta($column);
        $format = $col_config['format'] ?? null;

        return $this->formatColumnValue($value, $format, $field);
    }

    /**
     * Format a column value for display
     */
    private function formatColumnValue($value, $format, $field)
    {
        if ($value === '' || $value === null) {
            return '<span style="color:#999;">—</span>';
        }

        switch ($format) {
            case 'boolean':
                $save_format = $field['save_format'] ?? null;
                $is_true = FieldBuilder::isTruthyValue($value);
                if ($is_true) {
                    return '<span style="color:#46b450;">✓</span>';
                }
                return '<span style="color:#999;">✗</span>';

            case 'date':
                return esc_html(date_i18n(
                    get_option('date_format') . ' ' . get_option('time_format'),
                    strtotime($value)
                ));

            case 'link':
                return '<a href="' . esc_url($value) . '" target="_blank">'
                    . esc_html(wp_basename($value)) . '</a>';

            default:
                // Handle select field - show option label
                if (isset($field['options']) && is_array($field['options'])) {
                    return esc_html($field['options'][$value] ?? $value);
                }
                return esc_html($value);
        }
    }

    /**
     * Mark FieldBuilder columns as sortable
     */
    public function addSortableColumns($columns)
    {
        $fields = FieldBuilder::getFields();

        foreach ($fields as $key => $field) {
            $col_config = $field['admin_column'] ?? null;
            if (!$col_config || empty($col_config['enabled'])) {
                continue;
            }
            if (!empty($col_config['sortable'])) {
                $columns[$key] = $key;
            }
        }

        return $columns;
    }

    // =========================================================================
    // Filters
    // =========================================================================

    /**
     * Render filter dropdowns from FieldBuilder
     */
    public function renderFieldFilters()
    {
        global $typenow;
        if ($typenow !== 'participant') {
            return;
        }

        $fields = FieldBuilder::getFields();

        foreach ($fields as $key => $field) {
            $filter_config = $field['admin_filter'] ?? null;
            if (!$filter_config || empty($filter_config['enabled'])) {
                continue;
            }

            $current = isset($_GET[$key]) ? sanitize_text_field($_GET[$key]) : '';
            $type = $filter_config['type'] ?? 'select';

            if ($type === 'select' && !empty($filter_config['options'])) {
                $label = $filter_config['label'] ?? FieldBuilder::getFieldLabel($field);
                echo '<select name="' . esc_attr($key) . '">';
                echo '<option value="">' . esc_html($label) . '</option>';
                foreach ($filter_config['options'] as $opt_value => $opt_label) {
                    echo '<option value="' . esc_attr($opt_value) . '"'
                        . selected($current, (string) $opt_value, false)
                        . '>' . esc_html($opt_label) . '</option>';
                }
                echo '</select>';
            }
        }
    }

    /**
     * Apply field filters to WP_Query meta_query
     */
    public function applyFieldFilters($meta_query)
    {
        if (!is_array($meta_query)) {
            $meta_query = [];
        }

        $fields = FieldBuilder::getFields();

        foreach ($fields as $key => $field) {
            $filter_config = $field['admin_filter'] ?? null;
            if (!$filter_config || empty($filter_config['enabled'])) {
                continue;
            }

            if (isset($_GET[$key]) && $_GET[$key] !== '') {
                $meta_query[] = [
                    'key' => $key,
                    'value' => sanitize_text_field($_GET[$key]),
                    'compare' => '=',
                ];
            }
        }

        return $meta_query;
    }

    // =========================================================================
    // Export
    // =========================================================================

    /**
     * Add FieldBuilder fields to export column list
     */
    public function addExportFields($fields, $context = 'excel')
    {
        $definitions = FieldBuilder::getFields();

        foreach ($definitions as $key => $field) {
            $export_config = $field['export'] ?? null;

            // Default: export all non-system fields unless explicitly disabled
            $enabled = true;
            if ($export_config !== null) {
                $enabled = !empty($export_config['enabled']);
            }

            if (!$enabled) {
                continue;
            }

            // Skip if already in export fields
            if (isset($fields[$key])) {
                continue;
            }

            $header = $export_config['header'] ?? FieldBuilder::getFieldLabel($field);
            $priority = $export_config['priority'] ?? 50;

            $fields[$key] = [
                'header' => $header,
                'priority' => $priority,
            ];
        }

        // Sort by priority
        uasort($fields, function ($a, $b) {
            $pa = is_array($a) ? ($a['priority'] ?? 50) : 50;
            $pb = is_array($b) ? ($b['priority'] ?? 50) : 50;
            return $pa <=> $pb;
        });

        return $fields;
    }

    /**
     * Format export cell values using ParticipantState
     */
    public function formatExportValue($value, $key, $participant, $participant_id)
    {
        $field = FieldBuilder::getField($key);
        if (!$field) {
            return $value;
        }

        $export_config = $field['export'] ?? [];
        $format = $export_config['format'] ?? null;

        // If no value yet, try getting from ParticipantState
        if ($value === '' || $value === null) {
            $state = ParticipantState::get($participant_id);
            if ($state) {
                $value = $state->getMeta($key);
            }
        }

        if ($value === '' || $value === null) {
            return '';
        }

        switch ($format) {
            case 'boolean':
                return FieldBuilder::isTruthyValue($value) ? 'Áno' : 'Nie';
            case 'date':
                if (is_array($value)) {
                    return '';
                }
                return date_i18n(get_option('date_format'), strtotime((string) $value));
            default:
                // Handle select / multiselect options — multi-value fields
                // (e.g. lecture_preference) arrive as arrays; map each entry
                // to its option label and comma-join for spreadsheet display.
                if (isset($field['options']) && is_array($field['options'])) {
                    if (is_array($value)) {
                        $mapped = array_map(static function ($v) use ($field) {
                            $k = (string) $v;
                            return $field['options'][$k] ?? $v;
                        }, $value);
                        $mapped = array_filter(
                            array_map('strval', $mapped),
                            static function ($v) {
                                return $v !== '';
                            }
                        );
                        return implode(', ', $mapped);
                    }
                    $k = (string) $value;
                    return $field['options'][$k] ?? $value;
                }
                if (is_array($value)) {
                    return implode(', ', array_map('strval', $value));
                }
                return $value;
        }
    }

    // =========================================================================
    // Import
    // =========================================================================

    /**
     * Add FieldBuilder fields to import field mapping
     */
    public function addImportMapping($mapping)
    {
        if (!is_array($mapping)) {
            $mapping = [];
        }

        $fields = FieldBuilder::getFields();
        $languages = FieldBuilder::getAvailableLanguages();

        foreach ($fields as $key => $field) {
            $import_config = $field['import'] ?? null;

            // Default: import all fields unless explicitly disabled
            $enabled = true;
            if ($import_config !== null) {
                $enabled = !empty($import_config['enabled']);
            }

            if (!$enabled || isset($mapping[$key])) {
                continue;
            }

            // Build header aliases from all language labels
            $aliases = [];
            if (isset($field['labels']) && is_array($field['labels'])) {
                foreach ($field['labels'] as $label) {
                    if (!empty($label)) {
                        $aliases[] = $label;
                    }
                }
            }

            // Add explicit aliases from import config
            if (isset($import_config['header_aliases']) && is_array($import_config['header_aliases'])) {
                $aliases = array_merge($aliases, $import_config['header_aliases']);
            }

            // Add the key itself as alias
            $aliases[] = $key;

            $mapping[$key] = [
                'meta_key' => $key,
                'aliases' => array_unique($aliases),
                'type' => $field['type'] ?? 'text',
            ];
        }

        return $mapping;
    }
}
