<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin page for the Field Builder
 */
class FieldBuilderPage
{
    public function registerHooks()
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'handleActions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenuPage()
    {
        add_submenu_page(
            'edit.php?post_type=participant',
            __('Field Builder', 'alttag-registrations'),
            __('Field Builder', 'alttag-registrations'),
            'manage_options',
            'alttag-field-builder',
            [$this, 'renderPage']
        );
    }

    public function enqueueAssets($hook)
    {
        if (strpos($hook, 'alttag-field-builder') === false) {
            return;
        }

        wp_enqueue_script('jquery-ui-sortable');

        wp_enqueue_script(
            'alttag-field-builder',
            ALTTAG_REGISTRATIONS_URL . '/assets/js/field-builder.js',
            ['jquery', 'jquery-ui-sortable'],
            filemtime(ALTTAG_REGISTRATIONS_PATH . '/assets/js/field-builder.js'),
            true
        );

        wp_localize_script('alttag-field-builder', 'fieldBuilderConfig', [
            'languages' => FieldBuilder::getAvailableLanguages(),
            'fieldTypes' => self::getFieldTypes(),
            'groups' => self::getFieldGroupLabels(),
            'contexts' => self::getContextLabels(),
            'i18n' => [
                'confirm_delete' => __('Are you sure you want to delete this field?', 'alttag-registrations'),
                'field_key_required' => __('Field key is required', 'alttag-registrations'),
                'field_key_exists' => __('A field with this key already exists', 'alttag-registrations'),
            ],
        ]);

        wp_enqueue_style(
            'alttag-field-builder',
            ALTTAG_REGISTRATIONS_URL . '/assets/css/field-builder.css',
            [],
            filemtime(ALTTAG_REGISTRATIONS_PATH . '/assets/css/field-builder.css')
        );
    }

    /**
     * Handle form submissions and actions
     */
    public function handleActions()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Export
        if (isset($_GET['page']) && $_GET['page'] === 'alttag-field-builder'
            && isset($_GET['action']) && $_GET['action'] === 'export'
        ) {
            check_admin_referer('alttag_field_builder_export');
            $json = FieldBuilder::exportToJson();
            $filename = 'field-definitions-' . date('Y-m-d') . '.json';

            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $json;
            exit;
        }

        // Import
        if (isset($_POST['alttag_field_builder_import'])) {
            check_admin_referer('alttag_field_builder_import');

            if (empty($_FILES['import_file']['tmp_name'])) {
                add_settings_error('alttag_field_builder', 'no_file', __('No file selected', 'alttag-registrations'));
                return;
            }

            $json = file_get_contents($_FILES['import_file']['tmp_name']);
            $merge = !empty($_POST['import_merge']);
            $result = FieldBuilder::importFromJson($json, $merge);

            if ($result === true) {
                add_settings_error(
                    'alttag_field_builder',
                    'imported',
                    __('Fields imported successfully', 'alttag-registrations'),
                    'success'
                );
            } else {
                add_settings_error('alttag_field_builder', 'import_error', $result);
            }
        }

        // Save fields
        if (isset($_POST['alttag_field_builder_save'])) {
            check_admin_referer('alttag_field_builder_save');
            $this->saveFieldsFromPost();
            add_settings_error(
                'alttag_field_builder',
                'saved',
                __('Fields saved successfully', 'alttag-registrations'),
                'success'
            );
        }
    }

    /**
     * Save field definitions from POST data
     */
    private function saveFieldsFromPost()
    {
        $posted_fields = $_POST['fields'] ?? [];
        $field_order = $_POST['field_order'] ?? '';
        $languages = array_keys(FieldBuilder::getAvailableLanguages());
        $existing = FieldBuilder::getFields();

        $fields = [];
        $order = array_filter(explode(',', $field_order));
        $position = 0;

        foreach ($order as $field_key) {
            $position++;
            $field_key = sanitize_key($field_key);
            if (!isset($posted_fields[$field_key])) {
                continue;
            }

            $posted = $posted_fields[$field_key];
            $is_system = isset($existing[$field_key]['is_system']) && $existing[$field_key]['is_system'];

            // Build labels
            $labels = [];
            foreach ($languages as $lang) {
                $labels[$lang] = sanitize_text_field($posted['labels'][$lang] ?? '');
            }

            // Build ticket labels
            $ticket_labels = [];
            foreach ($languages as $lang) {
                $ticket_labels[$lang] = sanitize_text_field($posted['ticket_labels'][$lang] ?? '');
            }

            // Build contexts
            $contexts = [];
            $all_contexts = array_keys(self::getContextLabels());
            foreach ($all_contexts as $ctx) {
                if (!empty($posted['contexts'][$ctx])) {
                    $contexts[] = $ctx;
                }
            }

            // Build field definition
            $field = [
                'key' => $field_key,
                'labels' => $labels,
                'type' => sanitize_text_field($posted['type'] ?? 'text'),
                'required' => !empty($posted['required']),
                'class' => ['form-row-wide'],
                'priority' => $position * 10,
                'admin_class' => sanitize_text_field($posted['admin_class'] ?? 'short'),
                'admin_type' => sanitize_text_field($posted['admin_type'] ?? 'text'),
                'group' => sanitize_key($posted['group'] ?? 'common'),
                'display_conditions' => [],
                'ticket' => [
                    'enabled' => !empty($posted['ticket_enabled']),
                    'labels' => $ticket_labels,
                    'column' => sanitize_key($posted['ticket_column'] ?? 'first'),
                ],
                'ticket_only' => !empty($posted['ticket_only']),
                'is_system' => $is_system,
                'contexts' => $contexts,
            ];

            // Preserve display_conditions from existing
            if (isset($existing[$field_key]['display_conditions'])) {
                $field['display_conditions'] = $existing[$field_key]['display_conditions'];
            }

            // Save visible_product_ids (per-product visibility)
            if (isset($posted['visible_product_ids'])) {
                $raw = sanitize_text_field($posted['visible_product_ids']);
                $ids = array_filter(array_map('intval', array_map('trim', explode(',', $raw))));
                if (!empty($ids)) {
                    if (!is_array($field['display_conditions'])) {
                        $field['display_conditions'] = [];
                    }
                    $field['display_conditions']['product_ids'] = array_values($ids);
                } elseif (isset($field['display_conditions']['product_ids'])) {
                    unset($field['display_conditions']['product_ids']);
                }
            }

            // Save exclude_product_ids (per-product hide)
            if (isset($posted['exclude_product_ids'])) {
                $raw = sanitize_text_field($posted['exclude_product_ids']);
                $ids = array_filter(array_map('intval', array_map('trim', explode(',', $raw))));
                if (!empty($ids)) {
                    if (!is_array($field['display_conditions'])) {
                        $field['display_conditions'] = [];
                    }
                    $field['display_conditions']['exclude_product_ids'] = array_values($ids);
                } elseif (isset($field['display_conditions']['exclude_product_ids'])) {
                    unset($field['display_conditions']['exclude_product_ids']);
                }
            }

            // Preserve javascript_config from existing
            if (isset($existing[$field_key]['javascript_config'])) {
                $field['javascript_config'] = $existing[$field_key]['javascript_config'];
            }

            // Handle select options
            // Supports two formats per line:
            //   "Label only"        → value=label
            //   "value|Label"       → value and label separated by |
            if ($field['type'] === 'select') {
                if (isset($posted['options']) && trim((string) $posted['options']) !== '') {
                    $lines = array_filter(array_map('trim', explode("\n", $posted['options'])));
                    $options = [];
                    foreach ($lines as $line) {
                        if (strpos($line, '|') !== false) {
                            list($key, $label) = array_map('trim', explode('|', $line, 2));
                            $options[sanitize_text_field($key)] = sanitize_text_field($label);
                        } else {
                            $clean = sanitize_text_field($line);
                            $options[$clean] = $clean;
                        }
                    }
                    $field['options'] = $options;
                } elseif (isset($existing[$field_key]['options'])) {
                    // Preserve existing options when options textarea is missing or empty in form
                    $field['options'] = $existing[$field_key]['options'];
                }
            }

            // Handle save_format
            if (!empty($posted['save_format'])) {
                $field['save_format'] = sanitize_key($posted['save_format']);
            }

            // Admin column settings
            $field['admin_column'] = [
                'enabled' => !empty($posted['admin_column_enabled']),
                'position' => sanitize_key($posted['admin_column_position'] ?? 'before_actions'),
                'format' => sanitize_key($posted['admin_column_format'] ?? ''),
                'sortable' => !empty($posted['admin_column_sortable']),
                'priority' => $field['priority'],
            ];

            // Admin filter settings
            $field['admin_filter'] = [
                'enabled' => !empty($posted['admin_filter_enabled']),
            ];
            // Preserve filter options from existing
            if (isset($existing[$field_key]['admin_filter']['options'])) {
                $field['admin_filter']['options'] = $existing[$field_key]['admin_filter']['options'];
            }
            if (isset($existing[$field_key]['admin_filter']['label'])) {
                $field['admin_filter']['label'] = $existing[$field_key]['admin_filter']['label'];
            }

            // Export/import settings
            $field['export'] = [
                'enabled' => !empty($posted['export_enabled']),
                'priority' => $field['priority'],
            ];
            $field['import'] = [
                'enabled' => !empty($posted['import_enabled']),
            ];
            // Preserve import aliases from existing
            if (isset($existing[$field_key]['import']['header_aliases'])) {
                $field['import']['header_aliases'] = $existing[$field_key]['import']['header_aliases'];
            }

            $fields[$field_key] = $field;
        }

        FieldBuilder::saveFields($fields);

        $phone_required = !empty($fields['phone']['required']);
        update_option(
            'woocommerce_checkout_phone_field',
            $phone_required ? 'required' : 'optional'
        );
    }

    /**
     * Render the Field Builder page
     */
    public function renderPage()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $fields = FieldBuilder::getFields();
        $languages = FieldBuilder::getAvailableLanguages();
        $field_types = self::getFieldTypes();
        $groups = self::getFieldGroupLabels();
        $contexts = self::getContextLabels();

        settings_errors('alttag_field_builder');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Field Builder', 'alttag-registrations'); ?></h1>

            <div class="field-builder-actions" style="margin: 15px 0; display: flex; gap: 10px; align-items: center;">
                <!-- Export -->
                <a href="<?php echo esc_url(wp_nonce_url(
                    add_query_arg([
                        'page' => 'alttag-field-builder',
                        'action' => 'export',
                    ], admin_url('edit.php?post_type=participant')),
                    'alttag_field_builder_export'
                )); ?>" class="button">
                    <?php esc_html_e('Export JSON', 'alttag-registrations'); ?>
                </a>

                <!-- Import -->
                <form method="post" enctype="multipart/form-data" style="display: inline-flex; gap: 5px; align-items: center;">
                    <?php wp_nonce_field('alttag_field_builder_import'); ?>
                    <input type="file" name="import_file" accept=".json" />
                    <label><input type="checkbox" name="import_merge" value="1" /> <?php esc_html_e('Merge', 'alttag-registrations'); ?></label>
                    <button type="submit" name="alttag_field_builder_import" value="1" class="button">
                        <?php esc_html_e('Import JSON', 'alttag-registrations'); ?>
                    </button>
                </form>
            </div>

            <!-- Fields form -->
            <form method="post" id="field-builder-form">
                <?php wp_nonce_field('alttag_field_builder_save'); ?>
                <input type="hidden" name="field_order" id="field-order" value="<?php echo esc_attr(implode(',', array_keys($fields))); ?>" />

                <div id="field-builder-list">
                    <?php foreach ($fields as $field_key => $field) : ?>
                        <?php $this->renderFieldRow($field_key, $field, $languages, $field_types, $groups, $contexts); ?>
                    <?php endforeach; ?>
                </div>

                <div style="margin: 15px 0;">
                    <button type="button" id="add-field-btn" class="button button-secondary">
                        + <?php esc_html_e('Add Field', 'alttag-registrations'); ?>
                    </button>
                </div>

                <?php submit_button(__('Save Fields', 'alttag-registrations'), 'primary', 'alttag_field_builder_save'); ?>
            </form>

            <!-- Template for new field (hidden, cloned by JS) -->
            <div id="field-template" style="display: none;">
                <?php $this->renderFieldRow('__KEY__', self::getEmptyFieldTemplate(), $languages, $field_types, $groups, $contexts); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single field row (accordion item)
     */
    private function renderFieldRow($field_key, $field, $languages, $field_types, $groups, $contexts)
    {
        $is_system = !empty($field['is_system']);
        $first_lang = array_key_first($languages);
        $display_label = '';

        if (isset($field['labels']) && is_array($field['labels'])) {
            $display_label = $field['labels'][$first_lang] ?? reset($field['labels']);
        } elseif (isset($field['label'])) {
            $display_label = $field['label'];
        }

        ?>
        <div class="field-row" data-key="<?php echo esc_attr($field_key); ?>">
            <div class="field-row-header">
                <span class="field-drag-handle dashicons dashicons-menu"></span>
                <span class="field-key-label"><?php echo esc_html($field_key); ?></span>
                <span class="field-display-label"><?php echo esc_html($display_label); ?></span>
                <span class="field-type-badge"><?php echo esc_html($field['type'] ?? 'text'); ?></span>
                <?php if ($is_system) : ?>
                    <span class="field-system-badge"><?php esc_html_e('System', 'alttag-registrations'); ?></span>
                <?php endif; ?>
                <span class="field-toggle-btn dashicons dashicons-arrow-down"></span>
                <?php if (!$is_system) : ?>
                    <button type="button" class="field-delete-btn dashicons dashicons-trash" title="<?php esc_attr_e('Delete', 'alttag-registrations'); ?>"></button>
                <?php endif; ?>
            </div>

            <div class="field-row-body" style="display: none;">
                <table class="form-table field-settings-table">
                    <!-- Key -->
                    <tr>
                        <th><?php esc_html_e('Key', 'alttag-registrations'); ?></th>
                        <td>
                            <?php if ($is_system) : ?>
                                <code><?php echo esc_html($field_key); ?></code>
                            <?php else : ?>
                                <input type="text" class="field-key-input regular-text"
                                       value="<?php echo esc_attr($field_key); ?>"
                                       <?php echo $field_key !== '__KEY__' ? 'readonly' : ''; ?> />
                            <?php endif; ?>
                        </td>
                    </tr>

                    <!-- Labels per language -->
                    <tr>
                        <th><?php esc_html_e('Labels', 'alttag-registrations'); ?></th>
                        <td>
                            <?php foreach ($languages as $lang_code => $lang_name) : ?>
                                <div style="margin-bottom: 4px;">
                                    <label style="display: inline-block; width: 30px; font-weight: bold;">
                                        <?php echo esc_html(strtoupper($lang_code)); ?>
                                    </label>
                                    <input type="text"
                                           name="fields[<?php echo esc_attr($field_key); ?>][labels][<?php echo esc_attr($lang_code); ?>]"
                                           value="<?php echo esc_attr($field['labels'][$lang_code] ?? ''); ?>"
                                           class="regular-text" />
                                </div>
                            <?php endforeach; ?>
                        </td>
                    </tr>

                    <!-- Type -->
                    <tr>
                        <th><?php esc_html_e('Type', 'alttag-registrations'); ?></th>
                        <td>
                            <select name="fields[<?php echo esc_attr($field_key); ?>][type]">
                                <?php foreach ($field_types as $type_key => $type_label) : ?>
                                    <option value="<?php echo esc_attr($type_key); ?>"
                                        <?php selected($field['type'] ?? 'text', $type_key); ?>>
                                        <?php echo esc_html($type_label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                    <!-- Options (for select type) -->
                    <tr class="field-options-row" data-show-for-type="select">
                        <th><?php esc_html_e('Options (one per line)', 'alttag-registrations'); ?></th>
                        <td>
                            <?php
                            $options_lines = [];
                            if (!empty($field['options']) && is_array($field['options'])) {
                                foreach ($field['options'] as $opt_key => $opt_label) {
                                    if ((string) $opt_key === (string) $opt_label) {
                                        $options_lines[] = $opt_label;
                                    } else {
                                        $options_lines[] = $opt_key . '|' . $opt_label;
                                    }
                                }
                            }
                            ?>
                            <textarea name="fields[<?php echo esc_attr($field_key); ?>][options]"
                                      rows="4" class="large-text code"
                                      placeholder="1|1 osoba&#10;2|2 osoby"><?php
                                echo esc_textarea(implode("\n", $options_lines));
                            ?></textarea>
                            <p class="description">
                                <?php esc_html_e('One option per line. Use "value|Label" for separate value and label, or just "Label" for both.', 'alttag-registrations'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Required -->
                    <tr>
                        <th><?php esc_html_e('Required', 'alttag-registrations'); ?></th>
                        <td>
                            <input type="checkbox"
                                   name="fields[<?php echo esc_attr($field_key); ?>][required]"
                                   value="1"
                                   <?php checked(!empty($field['required'])); ?> />
                        </td>
                    </tr>

                    <!-- Visible for product IDs (per-product visibility) -->
                    <tr>
                        <th><?php esc_html_e('Visible only for products (IDs)', 'alttag-registrations'); ?></th>
                        <td>
                            <?php
                            $visible_ids = isset($field['display_conditions']['product_ids'])
                                ? implode(', ', (array) $field['display_conditions']['product_ids'])
                                : '';
                            ?>
                            <input type="text"
                                   name="fields[<?php echo esc_attr($field_key); ?>][visible_product_ids]"
                                   value="<?php echo esc_attr($visible_ids); ?>"
                                   placeholder="5267, 5277"
                                   class="regular-text" />
                            <p class="description">
                                <?php esc_html_e('Comma-separated WooCommerce product IDs. Leave empty to show for all products.', 'alttag-registrations'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Hidden for product IDs (per-product blacklist) -->
                    <tr>
                        <th><?php esc_html_e('Hidden for products (IDs)', 'alttag-registrations'); ?></th>
                        <td>
                            <?php
                            $excluded_ids = isset($field['display_conditions']['exclude_product_ids'])
                                ? implode(', ', (array) $field['display_conditions']['exclude_product_ids'])
                                : '';
                            ?>
                            <input type="text"
                                   name="fields[<?php echo esc_attr($field_key); ?>][exclude_product_ids]"
                                   value="<?php echo esc_attr($excluded_ids); ?>"
                                   placeholder="5267"
                                   class="regular-text" />
                            <p class="description">
                                <?php esc_html_e('Comma-separated WooCommerce product IDs where this field should be hidden. Useful for system fields you want to suppress on specific events.', 'alttag-registrations'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Group -->
                    <tr>
                        <th><?php esc_html_e('Group', 'alttag-registrations'); ?></th>
                        <td>
                            <select name="fields[<?php echo esc_attr($field_key); ?>][group]">
                                <?php foreach ($groups as $group_key => $group_label) : ?>
                                    <option value="<?php echo esc_attr($group_key); ?>"
                                        <?php selected($field['group'] ?? 'common', $group_key); ?>>
                                        <?php echo esc_html($group_label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                    <!-- Priority -->
                    <tr>
                        <th><?php esc_html_e('Priority', 'alttag-registrations'); ?></th>
                        <td>
                            <input type="number"
                                   name="fields[<?php echo esc_attr($field_key); ?>][priority]"
                                   value="<?php echo esc_attr($field['priority'] ?? 99); ?>"
                                   min="1" style="width: 70px;" />
                        </td>
                    </tr>

                    <!-- Contexts -->
                    <tr>
                        <th><?php esc_html_e('Show in', 'alttag-registrations'); ?></th>
                        <td>
                            <?php
                            $field_contexts = $field['contexts'] ?? ['checkout', 'admin', 'email', 'verification', 'export'];
                            foreach ($contexts as $ctx_key => $ctx_label) : ?>
                                <label style="margin-right: 12px;">
                                    <input type="checkbox"
                                           name="fields[<?php echo esc_attr($field_key); ?>][contexts][<?php echo esc_attr($ctx_key); ?>]"
                                           value="1"
                                           <?php checked(in_array($ctx_key, $field_contexts)); ?> />
                                    <?php echo esc_html($ctx_label); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>

                    <!-- Ticket only -->
                    <tr>
                        <th><?php esc_html_e('Ticket Only', 'alttag-registrations'); ?></th>
                        <td>
                            <input type="checkbox"
                                   name="fields[<?php echo esc_attr($field_key); ?>][ticket_only]"
                                   value="1"
                                   <?php checked(!empty($field['ticket_only'])); ?> />
                            <p class="description"><?php esc_html_e('Field is for ticket/verification display only, not shown on checkout', 'alttag-registrations'); ?></p>
                        </td>
                    </tr>

                    <!-- Ticket settings -->
                    <tr>
                        <th><?php esc_html_e('Ticket Display', 'alttag-registrations'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="fields[<?php echo esc_attr($field_key); ?>][ticket_enabled]"
                                       value="1"
                                       <?php
                                       $ticket_enabled = false;
                                       if (isset($field['ticket']['enabled'])) {
                                           $ticket_enabled = $field['ticket']['enabled'];
                                       } elseif (isset($field['ticket_label'])) {
                                           $ticket_enabled = true;
                                       }
                                       checked($ticket_enabled);
                                       ?> />
                                <?php esc_html_e('Show on ticket', 'alttag-registrations'); ?>
                            </label>
                            <br /><br />

                            <label><?php esc_html_e('Column:', 'alttag-registrations'); ?>
                                <?php
                                $ticket_column = $field['ticket']['column']
                                    ?? $field['ticket_column']
                                    ?? 'first';
                                ?>
                                <select name="fields[<?php echo esc_attr($field_key); ?>][ticket_column]">
                                    <option value="first" <?php selected($ticket_column, 'first'); ?>>
                                        <?php esc_html_e('First', 'alttag-registrations'); ?>
                                    </option>
                                    <option value="second" <?php selected($ticket_column, 'second'); ?>>
                                        <?php esc_html_e('Second', 'alttag-registrations'); ?>
                                    </option>
                                </select>
                            </label>
                            <br /><br />

                            <!-- Ticket labels -->
                            <strong><?php esc_html_e('Ticket Labels:', 'alttag-registrations'); ?></strong>
                            <?php foreach ($languages as $lang_code => $lang_name) :
                                $ticket_label_value = '';
                                if (isset($field['ticket']['labels'][$lang_code])) {
                                    $ticket_label_value = $field['ticket']['labels'][$lang_code];
                                }
                            ?>
                                <div style="margin: 4px 0;">
                                    <label style="display: inline-block; width: 30px; font-weight: bold;">
                                        <?php echo esc_html(strtoupper($lang_code)); ?>
                                    </label>
                                    <input type="text"
                                           name="fields[<?php echo esc_attr($field_key); ?>][ticket_labels][<?php echo esc_attr($lang_code); ?>]"
                                           value="<?php echo esc_attr($ticket_label_value); ?>"
                                           class="regular-text" />
                                </div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <!-- Admin Column -->
                    <tr>
                        <th><?php esc_html_e('Admin Column', 'alttag-registrations'); ?></th>
                        <td>
                            <?php $col = $field['admin_column'] ?? []; ?>
                            <label>
                                <input type="checkbox"
                                       name="fields[<?php echo esc_attr($field_key); ?>][admin_column_enabled]"
                                       value="1"
                                       <?php checked(!empty($col['enabled'])); ?> />
                                <?php esc_html_e('Show as column in participant list', 'alttag-registrations'); ?>
                            </label>
                            <br />
                            <label style="margin-top: 4px; display: inline-block;">
                                <?php esc_html_e('Position:', 'alttag-registrations'); ?>
                                <select name="fields[<?php echo esc_attr($field_key); ?>][admin_column_position]">
                                    <option value="before_actions" <?php selected($col['position'] ?? 'before_actions', 'before_actions'); ?>>
                                        <?php esc_html_e('Before Actions', 'alttag-registrations'); ?>
                                    </option>
                                    <option value="after_name" <?php selected($col['position'] ?? '', 'after_name'); ?>>
                                        <?php esc_html_e('After Name', 'alttag-registrations'); ?>
                                    </option>
                                    <option value="after_email" <?php selected($col['position'] ?? '', 'after_email'); ?>>
                                        <?php esc_html_e('After Email', 'alttag-registrations'); ?>
                                    </option>
                                </select>
                            </label>
                            <label style="margin-left: 10px;">
                                <?php esc_html_e('Format:', 'alttag-registrations'); ?>
                                <select name="fields[<?php echo esc_attr($field_key); ?>][admin_column_format]">
                                    <option value="" <?php selected($col['format'] ?? '', ''); ?>>
                                        <?php esc_html_e('Text', 'alttag-registrations'); ?>
                                    </option>
                                    <option value="boolean" <?php selected($col['format'] ?? '', 'boolean'); ?>>
                                        <?php esc_html_e('Boolean (✓/✗)', 'alttag-registrations'); ?>
                                    </option>
                                    <option value="date" <?php selected($col['format'] ?? '', 'date'); ?>>
                                        <?php esc_html_e('Date', 'alttag-registrations'); ?>
                                    </option>
                                </select>
                            </label>
                            <label style="margin-left: 10px;">
                                <input type="checkbox"
                                       name="fields[<?php echo esc_attr($field_key); ?>][admin_column_sortable]"
                                       value="1"
                                       <?php checked(!empty($col['sortable'])); ?> />
                                <?php esc_html_e('Sortable', 'alttag-registrations'); ?>
                            </label>
                        </td>
                    </tr>

                    <!-- Admin Filter -->
                    <tr>
                        <th><?php esc_html_e('Admin Filter', 'alttag-registrations'); ?></th>
                        <td>
                            <?php $filter = $field['admin_filter'] ?? []; ?>
                            <label>
                                <input type="checkbox"
                                       name="fields[<?php echo esc_attr($field_key); ?>][admin_filter_enabled]"
                                       value="1"
                                       <?php checked(!empty($filter['enabled'])); ?> />
                                <?php esc_html_e('Show as filter dropdown in participant list', 'alttag-registrations'); ?>
                            </label>
                        </td>
                    </tr>

                    <!-- Export -->
                    <tr>
                        <th><?php esc_html_e('Export/Import', 'alttag-registrations'); ?></th>
                        <td>
                            <?php $export = $field['export'] ?? []; ?>
                            <label>
                                <input type="checkbox"
                                       name="fields[<?php echo esc_attr($field_key); ?>][export_enabled]"
                                       value="1"
                                       <?php checked($export['enabled'] ?? true); ?> />
                                <?php esc_html_e('Include in Excel export', 'alttag-registrations'); ?>
                            </label>
                            <label style="margin-left: 15px;">
                                <input type="checkbox"
                                       name="fields[<?php echo esc_attr($field_key); ?>][import_enabled]"
                                       value="1"
                                       <?php checked(($field['import']['enabled'] ?? true)); ?> />
                                <?php esc_html_e('Accept in import', 'alttag-registrations'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // Static helpers
    // =========================================================================

    public static function getFieldTypes()
    {
        return [
            'text' => __('Text', 'alttag-registrations'),
            'email' => __('Email', 'alttag-registrations'),
            'textarea' => __('Textarea', 'alttag-registrations'),
            'select' => __('Select', 'alttag-registrations'),
            'checkbox' => __('Checkbox', 'alttag-registrations'),
            'country' => __('Country', 'alttag-registrations'),
        ];
    }

    public static function getFieldGroupLabels()
    {
        return [
            'common' => __('Common', 'alttag-registrations'),
            'billing' => __('Billing', 'alttag-registrations'),
            'participation' => __('Participation', 'alttag-registrations'),
            'hotel' => __('Hotel', 'alttag-registrations'),
            'custom' => __('Custom', 'alttag-registrations'),
        ];
    }

    public static function getContextLabels()
    {
        return [
            'checkout' => __('Checkout', 'alttag-registrations'),
            'admin' => __('Admin', 'alttag-registrations'),
            'email' => __('Email', 'alttag-registrations'),
            'verification' => __('Verification', 'alttag-registrations'),
            'export' => __('Export', 'alttag-registrations'),
        ];
    }

    private static function getEmptyFieldTemplate()
    {
        $languages = FieldBuilder::getAvailableLanguages();
        $labels = [];
        $ticket_labels = [];
        foreach (array_keys($languages) as $lang) {
            $labels[$lang] = '';
            $ticket_labels[$lang] = '';
        }

        return [
            'key' => '__KEY__',
            'labels' => $labels,
            'type' => 'text',
            'required' => false,
            'class' => ['form-row-wide'],
            'priority' => 99,
            'admin_class' => 'short',
            'admin_type' => 'text',
            'group' => 'custom',
            'display_conditions' => [],
            'ticket' => [
                'enabled' => false,
                'labels' => $ticket_labels,
                'column' => 'first',
            ],
            'ticket_only' => false,
            'is_system' => false,
            'contexts' => ['checkout', 'admin', 'email', 'verification', 'export'],
        ];
    }
}
