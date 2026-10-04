<?php

namespace Alttag\Registrations\Participant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles registration and configuration of the participant post type
 */
class PostType
{
    private $manager;

    public function __construct(Manager $manager)
    {
        $enable = apply_filters('alttag_registrations_enable_participants', true);

        if (!$enable) {
            return;
        }

        $this->manager = $manager;

        add_action('init', [$this, 'registerParticipantPostType']);
        add_action('add_meta_boxes', [$this, 'addParticipantMetaBoxes']);
        add_action('save_post_participant', [$this, 'saveParticipantMeta'], 10, 2);
        add_action('admin_footer', [$this, 'addParticipantAdminScripts']);
        add_action('wp_ajax_generate_variable_symbol', [$this, 'ajaxGenerateVariableSymbol']);

        // Enable search by email and other meta fields
        add_filter('posts_search', [$this, 'extendParticipantSearch'], 10, 2);
        add_filter('posts_join', [$this, 'extendParticipantSearchJoin'], 10, 2);
    }

    /**
     * Register the participant post type
     */
    public function registerParticipantPostType()
    {
        $labels = [
            'name' => __('Participants', 'alttag-registrations'),
            'singular_name' => __('Participant', 'alttag-registrations'),
            'menu_name' => __('Participants', 'alttag-registrations'),
            'name_admin_bar' => __('Participant', 'alttag-registrations'),
            'add_new' => __('Add New', 'alttag-registrations'),
            'add_new_item' => __('Add New Participant', 'alttag-registrations'),
            'edit_item' => __('Edit Participant', 'alttag-registrations'),
            'all_items' => __('All Participants', 'alttag-registrations'),
            'search_items' => __('Search Participants', 'alttag-registrations'),
            'not_found' => __('No participants found', 'alttag-registrations'),
            'not_found_in_trash' => __('No participants found in Trash', 'alttag-registrations'),
            'parent_item_colon' => __('Parent Participant:', 'alttag-registrations'),
            'featured_image' => __('Participant Photo', 'alttag-registrations'),
            'set_featured_image' => __('Set participant photo', 'alttag-registrations'),
            'remove_featured_image' => __('Remove participant photo', 'alttag-registrations'),
            'use_featured_image' => __('Use as participant photo', 'alttag-registrations'),
            'archives' => __('Participant archives', 'alttag-registrations'),
            'insert_into_item' => __('Insert into participant', 'alttag-registrations'),
            'uploaded_to_this_item' => __('Uploaded to this participant', 'alttag-registrations'),
            'filter_items_list' => __('Filter participants list', 'alttag-registrations'),
            'items_list_navigation' => __('Participants list navigation', 'alttag-registrations'),
            'items_list' => __('Participants list', 'alttag-registrations'),
            'item_published' => __('Participant published', 'alttag-registrations'),
            'item_published_privately' => __('Participant published privately', 'alttag-registrations'),
            'item_reverted_to_draft' => __('Participant reverted to draft', 'alttag-registrations'),
            'item_scheduled' => __('Participant scheduled', 'alttag-registrations'),
            'item_updated' => __('Participant updated', 'alttag-registrations'),
            'item_link' => __('Participant link', 'alttag-registrations'),
            'item_link_description' => __('A link to the participant', 'alttag-registrations'),
        ];

        $args = [
            'labels'              => $labels,
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'capability_type'     => 'post',
            'hierarchical'        => false,
            'menu_icon'           => 'dashicons-groups',
            'supports'            => ['title'],
            'show_in_rest'        => true,
        ];

        register_post_type('participant', $args);
    }

    /**
     * Extend participant search to include email and variable symbol meta fields
     *
     * @param string $search The search SQL
     * @param \WP_Query $query The query object
     * @return string Modified search SQL
     */
    public function extendParticipantSearch($search, $query)
    {
        global $wpdb;

        if (!is_admin() || !$query->is_main_query() || !$query->is_search()) {
            return $search;
        }

        if ($query->get('post_type') !== 'participant') {
            return $search;
        }

        $search_term = $query->get('s');
        if (empty($search_term)) {
            return $search;
        }

        // Fields to search in (in addition to post_title)
        $searchable_meta_keys = apply_filters('alttag_registrations_searchable_meta_fields', [
            'email',
            'variable_symbol',
            'phone',
        ]);

        // Build meta search conditions
        $meta_conditions = [];
        foreach ($searchable_meta_keys as $meta_key) {
            $meta_conditions[] = $wpdb->prepare(
                "participant_search_meta.meta_key = %s AND participant_search_meta.meta_value LIKE %s",
                $meta_key,
                '%' . $wpdb->esc_like($search_term) . '%'
            );
        }

        if (empty($meta_conditions)) {
            return $search;
        }

        // Add meta search to existing search
        $meta_search = '(' . implode(' OR ', $meta_conditions) . ')';

        // Modify the search clause to include meta search
        if (!empty($search)) {
            $subquery = "SELECT post_id FROM {$wpdb->postmeta} AS participant_search_meta WHERE {$meta_search}";
            $search = preg_replace(
                '/^\s*AND\s*\(/',
                "AND (({$wpdb->posts}.ID IN ({$subquery})) OR (",
                $search
            );
            $search = preg_replace('/\)\s*$/', '))', $search);
        }

        return $search;
    }

    /**
     * Add JOIN for meta search (not needed with subquery approach, but kept for potential future use)
     *
     * @param string $join The join SQL
     * @param \WP_Query $query The query object
     * @return string Modified join SQL
     */
    public function extendParticipantSearchJoin($join, $query)
    {
        // Currently using subquery approach, so no additional join needed
        return $join;
    }

    /**
     * Add meta boxes to the participant edit screen
     */
    public function addParticipantMetaBoxes()
    {
        add_meta_box(
            'participant_details',
            __('Participant Details', 'alttag-registrations'),
            [$this, 'renderParticipantMetaBox'],
            'participant',
            'normal',
            'high'
        );
    }

    /**
     * Render the participant meta box
     *
     * @param WP_Post $post The post object
     */
    public function renderParticipantMetaBox($post)
    {
        wp_nonce_field('participant_meta_box', 'participant_meta_box_nonce');

        $metaFields = $this->manager->getMetaFields();
        $countries = $this->getWoocommerceCountries();

        // Group fields into sections
        $sections = [
            'personal' => [
                'title' => __('Personal Information', 'alttag-registrations'),
                'fields' => ['first_name', 'last_name', 'email', 'phone'],
            ],
            'company' => [
                'title' => __('Company Information', 'alttag-registrations'),
                'fields' => ['company_name', 'business_id', 'tax_id', 'vat_id'],
            ],
            'address' => [
                'title' => __('Address', 'alttag-registrations'),
                'fields' => ['street', 'city', 'zip', 'country'],
            ],
            'registration' => [
                'title' => __('Registration Details', 'alttag-registrations'),
                'fields' => array_merge(
                    ['variable_symbol', 'order_id', 'product_name', 'price', 'payment_method', 'registration_status'],
                    \Alttag\Registrations\RegistrationContext::current()->isMultilingual() ? ['language'] : [],
                    ['used_coupons', 'order_comments']
                ),
            ],
            'documents' => [
                'title' => __('Documents', 'alttag-registrations'),
                'fields' => ['invoice_id', 'invoice_url', 'ticket_url', 'ticket_file_path'],
            ],
            'qr_code' => [
                'title' => __('QR Code', 'alttag-registrations'),
                'fields' => ['qr_code_url', 'qr_code_img'],
            ],
            'system' => [
                'title' => __('System Information', 'alttag-registrations'),
                'fields' => ['create_date', 'update_date', 'imported_at', 'registration_history', 'facial_ticket'],
            ],
        ];

        // Add livestream section if livestream is enabled
        if (\Alttag\Registrations\RegistrationContext::current()->isLivestreamEnabled()) {
            $sections['livestream'] = [
                'title' => __('Livestream', 'alttag-registrations'),
                'fields' => ['is_livestream_user', 'livestream_access'],
            ];
        }

        // Allow filtering of sections
        $sections = apply_filters('alttag_registrations_meta_box_sections', $sections, $post->ID);

        // Every field once only, in the first section that asks for it. Modules
        // extend these sections independently and cannot see each other, so
        // without this the same input renders twice.
        $seen = [];
        foreach ($sections as $section_id => $section) {
            if (empty($section['fields']) || !is_array($section['fields'])) {
                continue;
            }
            $unique = [];
            foreach ($section['fields'] as $field_id) {
                if (isset($seen[$field_id])) {
                    continue;
                }
                $seen[$field_id] = true;
                $unique[] = $field_id;
            }
            $sections[$section_id]['fields'] = $unique;
        }

        echo '<div class="participant-meta-box">';

        foreach ($sections as $section_id => $section) {
            echo '<div class="participant-section">';
            echo '<h3>' . esc_html($section['title']) . '</h3>';
            echo '<table class="form-table">';

            foreach ($section['fields'] as $field_id) {
                if (!isset($metaFields[$field_id])) {
                    continue;
                }

                $field = $metaFields[$field_id];
                $pState = \Alttag\Registrations\ParticipantState::get($post->ID);
                $value = $pState ? $pState->getMeta($field_id) : '';
                $readonly = isset($field['readonly']) && $field['readonly'];
                $required = isset($field['required']) && $field['required'];

                echo '<tr>';
                
                $label = isset($field['admin_label']) ? $field['admin_label'] : $field['label'];
                echo '<th><label for="' . esc_attr($field_id) . '">' . esc_html($label) . ($required ? ' <span class="required">*</span>' : '') . '</label></th>';

                echo '<td>';

                do_action('alttag_registrations_participant_before_meta_box_field_value', $field, $value, $post->ID);

                switch ($field['type']) {
                    case 'html':
                        // Read-only composite field — value comes from a
                        // callback so dynamic HTML (e.g. multi-row summaries)
                        // can be rendered without any input element.
                        if (isset($field['value_callback']) && is_callable($field['value_callback'])) {
                            $html_value = (string) call_user_func($field['value_callback'], $post->ID, $field_id, $value);
                        } else {
                            $html_value = is_scalar($value) ? (string) $value : '';
                        }
                        if ($html_value === '') {
                            echo '<span class="description">—</span>';
                        } else {
                            echo wp_kses($html_value, [
                                'div' => ['style' => [], 'class' => []],
                                'span' => ['style' => [], 'class' => []],
                                'p' => ['style' => [], 'class' => []],
                                'strong' => [],
                                'em' => [],
                                'br' => [],
                                'ul' => ['style' => [], 'class' => []],
                                'ol' => ['style' => [], 'class' => []],
                                'li' => ['style' => [], 'class' => []],
                                'a' => ['href' => [], 'target' => [], 'rel' => []],
                            ]);
                        }
                        break;

                    case 'multiselect':
                        $selected = is_array($value) ? array_map('strval', $value) : [];
                        $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];
                        echo '<select name="' . esc_attr($field_id) . '[]" id="' . esc_attr($field_id) . '"'
                            . ' multiple="multiple"' . ($readonly ? ' disabled' : '') . ' size="' . max(3, min(8, count($options))) . '">';
                        foreach ($options as $option_value => $option_label) {
                            if ((string) $option_value === '') {
                                continue;
                            }
                            $is_selected = in_array((string) $option_value, $selected, true) ? ' selected' : '';
                            echo '<option value="' . esc_attr($option_value) . '"' . $is_selected . '>'
                                . esc_html($option_label) . '</option>';
                        }
                        echo '</select>';
                        break;

                    case 'select':
                        // Use default value if value is empty and default is set
                        $select_value = $value;
                        if (empty($select_value) && isset($field['default'])) {
                            $select_value = $field['default'];
                        }

                        if ($field_id === 'country') {
                            echo '<select name="' . esc_attr($field_id) . '" id="' . esc_attr($field_id) . '"' . ($readonly ? ' disabled' : '') . '>';
                            echo '<option value="">' . __('-- Select Country --', 'alttag-registrations') . '</option>';
                            foreach ($countries as $code => $name) {
                                echo '<option value="' . esc_attr($code) . '"' . selected($select_value, $code, false) . '>' . esc_html($name) . '</option>';
                            }
                            echo '</select>';
                        } else {
                            echo '<select name="' . esc_attr($field_id) . '" id="' . esc_attr($field_id) . '"' . ($readonly ? ' disabled' : '') . '>';
                            echo '<option value="">' . __('-- Select --', 'alttag-registrations') . '</option>';
                            foreach ($field['options'] as $option_value => $option_label) {
                                echo '<option value="' . esc_attr($option_value) . '"' . selected($select_value, $option_value, false) . '>' . esc_html($option_label) . '</option>';
                            }
                            echo '</select>';
                        }
                        break;

                    case 'textarea':
                        echo '<textarea name="' . esc_attr($field_id) . '" id="' . esc_attr($field_id) . '" rows="5" cols="50"' . ($readonly ? ' readonly' : '') . '>' . esc_textarea($value) . '</textarea>';
                        break;

                    case 'checkbox':
                        echo '<input type="checkbox" name="' . esc_attr($field_id) . '" id="' . esc_attr($field_id) . '" value="1"' . checked($value, '1', false) . ($readonly ? ' disabled' : '') . '>';
                        break;

                    case 'qr_code':
                        if (!empty($value)) {
                            echo '<div class="qr-code-container">';
                            echo '<img src="data:image/png;base64,' . esc_attr($value) . '" alt="QR Code">';
                            echo '</div>';
                        } else {
                            echo '<p>' . __('No QR code generated yet.', 'alttag-registrations') . '</p>';
                        }
                        break;

                    case 'url':
                        echo '<input type="url" name="' . esc_attr($field_id) . '" id="' . esc_attr($field_id) . '" value="' . esc_attr($value) . '" class="regular-text"' . ($readonly ? ' readonly' : '') . '>';
                        if (!empty($value)) {
                            echo ' <a href="' . esc_url($value) . '" target="_blank">' . __('View', 'alttag-registrations') . '</a>';
                        }
                        break;

                    case 'datetime-local':
                        $formatted_value = !empty($value) ? date('Y-m-d\TH:i', strtotime($value)) : '';
                        echo '<input type="datetime-local" name="' . esc_attr($field_id) . '" id="' . esc_attr($field_id) . '" value="' . esc_attr($formatted_value) . '" class="regular-text"' . ($readonly ? ' readonly' : '') . '>';
                        break;

                    default:
                        $input_value = is_scalar($value) ? (string) $value : '';
                        echo '<input type="' . esc_attr($field['type']) . '" name="' . esc_attr($field_id) . '" id="' . esc_attr($field_id) . '" value="' . esc_attr($input_value) . '" class="regular-text"' . ($readonly ? ' readonly' : '') . ($required ? ' required' : '') . (isset($field['step']) ? ' step="' . esc_attr($field['step']) . '"' : '') . '>';
                        break;
                }

                do_action('alttag_registrations_participant_after_meta_box_field_value', $field_id, $field, $value, $post->ID);

                echo '</td>';
                echo '</tr>';
            }

            echo '</table>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Save participant meta data
     *
     * @param int $post_id The post ID
     * @param WP_Post $post The post object
     */
    public function saveParticipantMeta($post_id, $post)
    {
        if (!isset($_POST['participant_meta_box_nonce']) ||
            !wp_verify_nonce($_POST['participant_meta_box_nonce'], 'participant_meta_box') ||
            defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ||
            !current_user_can('edit_post', $post_id)) {
            return;
        }

        // Auto-generate post title from first_name and last_name if empty
        $this->autoGeneratePostTitle($post_id, $post);

        // Handle variable symbol validation and generation
        $this->handleVariableSymbol($post_id, $post);

        // Set default registration status to pending for new participants
        if (!get_post_meta($post_id, 'registration_status', true)) {
            update_post_meta($post_id, 'registration_status', 'pending');
        }

        $metaFields = $this->manager->getMetaFields();
        foreach ($metaFields as $key => $field) {
            // Skip read-only fields if they already have values or variable_symbol (handled separately)
            if ((in_array($key, ['order_id', 'variable_symbol']) && !empty(get_post_meta($post_id, $key, true))) ||
                (isset($field['readonly']) && $field['readonly']) ||
                $key === 'variable_symbol') {
                continue;
            }

            if (isset($_POST[$key])) {
                // Use appropriate sanitization based on field type
                if ($field['type'] === 'textarea') {
                    $value = sanitize_textarea_field($_POST[$key]);
                } elseif ($field['type'] === 'multiselect') {
                    $raw = is_array($_POST[$key]) ? $_POST[$key] : [];
                    $value = array_values(array_filter(array_map(
                        function ($v) {
                            return sanitize_text_field(wp_unslash((string) $v));
                        },
                        $raw
                    ), 'strlen'));
                } else {
                    $value = sanitize_text_field($_POST[$key]);
                }
                update_post_meta($post_id, $key, $value);
            } elseif ($field['type'] === 'multiselect') {
                // Multi-select fields submit nothing when no option is checked.
                update_post_meta($post_id, $key, []);
            } elseif ($field['type'] === 'checkbox') {
                delete_post_meta($post_id, $key);
            }
        }

        // Generate ticket if verification manager is available
        $verificationManager = apply_filters('alttag_registrations_verification_manager', null);
        if ($verificationManager) {
            $verificationManager->generateAndSaveTicket($post_id);
        }
    }

    /**
     * Auto-generate post title from first and last name
     */
    private function autoGeneratePostTitle($post_id, $post)
    {
        if (empty($post->post_title) || $post->post_title === 'Auto Draft') {
            $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
            $last_name = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';

            if (!empty($first_name) || !empty($last_name)) {
                $new_title = trim($first_name . ' ' . $last_name);
                if (!empty($new_title)) {
                    // Remove save_post hook temporarily to avoid infinite loop
                    remove_action('save_post_participant', [$this, 'saveParticipantMeta'], 10);
                    wp_update_post([
                        'ID' => $post_id,
                        'post_title' => $new_title
                    ]);
                    // Re-add the hook
                    add_action('save_post_participant', [$this, 'saveParticipantMeta'], 10, 2);
                }
            }
        }
    }

    /**
     * Handle variable symbol validation and generation
     */
    private function handleVariableSymbol($post_id, $post)
    {
        $is_new_post = ($post->post_status === 'auto-draft' || empty(get_post_meta($post_id, 'variable_symbol', true)));
        $variable_symbol = isset($_POST['variable_symbol']) ? sanitize_text_field($_POST['variable_symbol']) : '';

        // If no variable symbol provided, generate one
        if (empty($variable_symbol) && $is_new_post) {
            $variable_symbol = $this->generateUniqueVariableSymbol();
            update_post_meta($post_id, 'variable_symbol', $variable_symbol);
        } elseif (!empty($variable_symbol)) {
            // Check if variable symbol already exists
            if ($this->variableSymbolExists($variable_symbol, $post_id)) {
                // Add admin notice about duplicate
                add_action('admin_notices', function() use ($variable_symbol) {
                    echo '<div class="notice notice-error"><p>';
                    echo sprintf(__('Warning: Variable symbol "%s" already exists for another participant. Please choose a different one.', 'alttag-registrations'), $variable_symbol);
                    echo '</p></div>';
                });

                // Generate new unique symbol
                $new_symbol = $this->generateUniqueVariableSymbol();
                update_post_meta($post_id, 'variable_symbol', $new_symbol);

                add_action('admin_notices', function() use ($new_symbol) {
                    echo '<div class="notice notice-info"><p>';
                    echo sprintf(__('A new unique variable symbol has been automatically generated: "%s"', 'alttag-registrations'), $new_symbol);
                    echo '</p></div>';
                });
            } else {
                // Variable symbol is unique, save it
                // Add 'M' prefix to variable symbol if no order ID exists
                if (empty($_POST['order_id']) && !str_starts_with($variable_symbol, 'M')) {
                    $variable_symbol = 'M' . $variable_symbol;
                }
                update_post_meta($post_id, 'variable_symbol', $variable_symbol);
            }
        }
    }

    /**
     * Generate unique variable symbol based on timestamp
     */
    private function generateUniqueVariableSymbol()
    {
        do {
            // Generate symbol with M prefix for manual participants
            $year = date('Y');
            $timestamp = time();
            $symbol = 'M' . $year . substr($timestamp, -6); // M + Year + last 6 digits of timestamp

            // Add small random component to avoid collisions
            $symbol .= str_pad(rand(1, 99), 2, '0', STR_PAD_LEFT);

        } while ($this->variableSymbolExists($symbol));

        return $symbol;
    }

    /**
     * Check if variable symbol already exists
     */
    private function variableSymbolExists($variable_symbol, $exclude_post_id = null)
    {
        global $wpdb;

        $query = "SELECT post_id FROM {$wpdb->postmeta} pm
                  JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                  WHERE pm.meta_key = 'variable_symbol'
                  AND pm.meta_value = %s
                  AND p.post_type = 'participant'
                  AND p.post_status != 'trash'";

        $params = [$variable_symbol];

        if ($exclude_post_id) {
            $query .= " AND pm.post_id != %d";
            $params[] = $exclude_post_id;
        }

        $existing = $wpdb->get_var($wpdb->prepare($query, $params));

        return !empty($existing);
    }

    /**
     * Add admin scripts for participant enhancement
     */
    public function addParticipantAdminScripts()
    {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'participant') {
            global $post;
            $is_new_participant = !$post || $post->post_status === 'auto-draft' || empty(get_post_meta($post->ID, 'variable_symbol', true));

            if ($is_new_participant) {
                ?>
                <script>
                jQuery(document).ready(function($) {
                    // Auto-generate variable symbol if field is empty
                    var variableSymbolField = $('#variable_symbol');
                    if (variableSymbolField.length && variableSymbolField.val() === '') {
                        // Use AJAX to generate variable symbol from PHP
                        $.post(ajaxurl, {
                            action: 'generate_variable_symbol',
                            nonce: '<?php echo wp_create_nonce('generate_variable_symbol'); ?>'
                        }, function(response) {
                            if (response.success) {
                                variableSymbolField.val(response.data.variable_symbol);
                            }
                        });
                    }

                    // Store the expected title based on current name values
                    var expectedTitle = '';

                    function updateExpectedTitle() {
                        var firstName = $('#first_name').val().trim();
                        var lastName = $('#last_name').val().trim();
                        expectedTitle = (firstName + ' ' + lastName).trim();
                    }

                    // Auto-generate post title from existing name fields
                    function updatePostTitle() {
                        var firstName = $('#first_name').val().trim();
                        var lastName = $('#last_name').val().trim();
                        var titleField = $('#titlediv #title, input[name="post_title"]');
                        var currentTitle = titleField.val().trim();

                        if ((firstName || lastName)) {
                            var newTitle = (firstName + ' ' + lastName).trim();

                            // Update title if:
                            // - Title is empty, Auto Draft, or starts with "Participant #"
                            // - Current title matches what we expected before editing started
                            var isBasicTitle = currentTitle === '' ||
                                             currentTitle === 'Auto Draft' ||
                                             currentTitle.indexOf('Účastník #') === 0;

                            var isExpectedTitle = currentTitle === expectedTitle;

                            var shouldUpdate = isBasicTitle || isExpectedTitle;

                            if (shouldUpdate && newTitle) {
                                titleField.val(newTitle);
                                // Trigger title update for WordPress
                                titleField.trigger('input').trigger('change');
                                // Update expected title for next change
                                expectedTitle = newTitle;
                            }
                        }
                    }

                    // Initialize expected title on page load
                    updateExpectedTitle();
                    updatePostTitle();

                    // Before editing starts, store current expected title
                    $('#first_name, #last_name').on('focus', function() {
                        updateExpectedTitle();
                    });

                    // Update when name fields change
                    $('#first_name, #last_name').on('input', updatePostTitle);
                });
                </script>
                <?php
            }
        }
    }

    /**
     * Get WooCommerce countries (delegates to core helper)
     */
    private function getWoocommerceCountries()
    {
        return \Alttag\Registrations\get_woocommerce_countries();
    }

    /**
     * AJAX handler to generate unique variable symbol
     */
    public function ajaxGenerateVariableSymbol()
    {
        // Check nonce for security
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'generate_variable_symbol')) {
            wp_send_json_error('Invalid nonce');
        }

        // Check permissions
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Generate unique variable symbol
        $variable_symbol = $this->generateUniqueVariableSymbol();

        wp_send_json_success(['variable_symbol' => $variable_symbol]);
    }
}
