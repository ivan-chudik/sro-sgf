<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles ticket designer settings and configuration
 */
class TicketDesignerSettings
{
    private $option_name = 'alttag_ticket_design_settings';

    public function registerHooks()
    {
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_post_save_ticket_design_settings', [$this, 'saveSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_preview_ticket_design', [$this, 'ajaxPreviewTicket']);
        add_action('wp_ajax_get_global_ticket_settings', [$this, 'ajaxGetGlobalSettings']);
    }

    /**
     * Check if Polylang is active and has multiple languages
     *
     * @return bool
     */
    public function isMultilingual()
    {
        return \Alttag\Registrations\RegistrationContext::current()->isMultilingual();
    }

    /**
     * Get available languages
     *
     * @return array Array of language slugs and names
     */
    public function getAvailableLanguages()
    {
        if (!\Alttag\Registrations\RegistrationContext::current()->hasPolylang()) {
            return [];
        }

        $languages = [];
        $slugs = pll_languages_list(['fields' => 'slug']);

        foreach ($slugs as $slug) {
            $lang = \PLL()->model->get_language($slug);
            if ($lang) {
                $languages[$slug] = $lang->name;
            }
        }

        return $languages;
    }

    /**
     * Get current language for admin editing
     *
     * @return string Language code
     */
    public function getCurrentEditLanguage()
    {
        // Check if language is set in URL parameter
        if (isset($_GET['ticket_lang']) && !empty($_GET['ticket_lang'])) {
            return sanitize_text_field($_GET['ticket_lang']);
        }

        $has_explicit_product = isset($_GET['ticket_product_id']) && $_GET['ticket_product_id'] !== '';
        $product_id = $this->getCurrentEditProductId();
        if ($has_explicit_product && $product_id > 0) {
            $product_language = $this->getProductLanguage($product_id);
            if (!empty($product_language)) {
                return $product_language;
            }
        }

        // Fall back to default language
        return \Alttag\Registrations\get_default_language();
    }

    /**
     * Get current product ID for admin editing
     *
     * @return int Product ID (0 = global settings)
     */
    public function getCurrentEditProductId()
    {
        if (isset($_GET['ticket_product_id']) && $_GET['ticket_product_id'] !== '') {
            return intval($_GET['ticket_product_id']);
        }
        return 0;
    }

    /**
     * Get available WooCommerce products for the product selector
     *
     * @return array Array of product ID => product name
     */
    public function getAvailableProducts()
    {
        if (!function_exists('wc_get_products')) {
            return [];
        }

        $products = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $result = [];
        $default_lang = $this->isMultilingual() && function_exists('pll_default_language')
            ? pll_default_language('slug')
            : null;

        foreach ($products as $product) {
            $product_id = $product->get_id();

            // Skip products that don't generate tickets (livestream products and
            // anything with _generates_ticket=0, e.g. memberships, hotels).
            if (!\Alttag\Registrations\product_generates_ticket($product_id)) {
                continue;
            }

            if ($default_lang && function_exists('pll_get_post_language')) {
                $lang = pll_get_post_language($product_id, 'slug');
                if ($lang && $lang !== $default_lang) {
                    continue;
                }
            }

            $result[$product_id] = $product->get_name();
        }

        return apply_filters('alttag_registrations_ticket_designer_available_products', $result);
    }

    public function getProductLanguage($productId)
    {
        if ($productId <= 0 || !function_exists('pll_get_post_language')) {
            return '';
        }

        $language = pll_get_post_language($productId, 'slug');
        return is_string($language) ? $language : '';
    }

    /**
     * Get meta key for a specific language (used for per-product settings)
     *
     * @param string $language Language code
     * @return string Meta key
     */
    private function getMetaKeyForLanguage($language)
    {
        $base_key = '_alttag_ticket_design_settings';
        $default_lang = \Alttag\Registrations\get_default_language();

        if ($language === $default_lang) {
            return $base_key;
        }

        return $base_key . '_' . $language;
    }

    /**
     * Get option name for a specific language
     *
     * @param string $language Language code
     * @return string Option name
     */
    private function getOptionNameForLanguage($language)
    {
        $default_lang = \Alttag\Registrations\get_default_language();

        // Use base option name for default language (backwards compatibility)
        if ($language === $default_lang) {
            return $this->option_name;
        }

        return $this->option_name . '_' . $language;
    }

    /**
     * Add admin menu
     */
    public function addAdminMenu()
    {
        add_submenu_page(
            'edit.php?post_type=participant',
            __('Ticket Designer', 'alttag-registrations'),
            __('Ticket Designer', 'alttag-registrations'),
            'manage_options',
            'ticket-designer',
            [$this, 'renderDesignerPage']
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueueAssets($hook)
    {
        if ($hook !== 'participant_page_ticket-designer') {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style('wp-color-picker');

        // Shoelace design tokens (light theme) + component autoloader from CDN.
        // Provides --sl-* CSS variables and on-demand web components like
        // <sl-switch> / <sl-card>. No build step, only enqueued on this screen.
        wp_enqueue_style(
            'shoelace-light',
            'https://cdn.jsdelivr.net/npm/@shoelace-style/shoelace@2.20.0/cdn/themes/light.css',
            [],
            '2.20.0'
        );
        // Inter font — the modern sans-serif Shoelace's design language expects.
        wp_enqueue_style(
            'inter-font',
            'https://rsms.me/inter/inter.css',
            [],
            null
        );
        // Autoloader loads Shoelace components on demand as their tags appear.
        add_action('admin_print_footer_scripts', function () {
            echo "<script type=\"module\" src=\"https://cdn.jsdelivr.net/npm/@shoelace-style/shoelace@2.20.0/cdn/shoelace-autoloader.js\"></script>\n";
        }, 5);

        $css_file = ALTTAG_REGISTRATIONS_PATH . '/assets/css/ticket-designer-settings.css';
        $css_version = file_exists($css_file) ? filemtime($css_file) : '1.0.0';

        wp_enqueue_style(
            'ticket-designer-settings-style',
            ALTTAG_REGISTRATIONS_URL . 'assets/css/ticket-designer-settings.css',
            [],
            $css_version
        );

        $js_file = ALTTAG_REGISTRATIONS_PATH . '/assets/js/ticket-designer-settings.js';
        $js_version = file_exists($js_file) ? filemtime($js_file) : '1.0.0';

        wp_enqueue_script(
            'ticket-designer-settings-script',
            ALTTAG_REGISTRATIONS_URL . 'assets/js/ticket-designer-settings.js',
            ['jquery', 'wp-color-picker', 'jquery-ui-sortable'],
            $js_version,
            true
        );

        wp_localize_script('ticket-designer-settings-script', 'ticketDesignerSettings', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ticket_designer_settings_nonce'),
            'defaults' => $this->getDefaultSettings(),
            'i18n' => [
                'selectImage' => __('Select Image', 'alttag-registrations'),
                'useThisImage' => __('Use this image', 'alttag-registrations'),
                'selectLogo' => __('Select Logo', 'alttag-registrations'),
                'logoImage' => __('Logo Image', 'alttag-registrations'),
                'imageUrl' => __('Image URL', 'alttag-registrations'),
                'remove' => __('Remove', 'alttag-registrations'),
                'unsupportedFormat' => __('Unsupported image format. Please use PNG, JPG or GIF. WebP is not supported for PDF tickets.', 'alttag-registrations'),
                'errorPreview' => __('Error generating preview:', 'alttag-registrations'),
                'errorPreviewRetry' => __('Error generating preview. Please try again.', 'alttag-registrations'),
                'importSuccess' => __('Settings imported successfully!', 'alttag-registrations'),
                'errorImport' => __('Error importing settings:', 'alttag-registrations'),
                'confirmCopyGlobal' => __('This will replace all current settings with the global ticket design. Continue?', 'alttag-registrations'),
                'errorLoadGlobal' => __('Could not load global settings.', 'alttag-registrations'),
                'errorLoadGlobalRetry' => __('Error loading global settings. Please try again.', 'alttag-registrations'),
            ],
        ]);
    }

    /**
     * Get default settings
     */
    public function getDefaultSettings()
    {
        return [
            'header_image' => '',
            'hidden_default_fields' => [],
            'ticket_frame' => [
                'enabled'            => 0,
                'height'             => 438,
                'y'                  => 150,
                'logo_gap'           => 0,
                'border_radius'      => 26,
                'bg_type'            => 'gradient',
                'bg_color'           => '#C479E6',
                'bg_color_2'         => '#54C8EA',
                'gradient_direction' => 'horizontal',
                'bg_image'           => '',
                'stub_color'         => '#9C1C90',
                'stub_width'         => 107,
                'perf_enabled'       => 1,
                'perf_x'             => 1013,
                'perf_color'         => '#0D1A26',
                'right_text'         => "To validate your ticket, please scan\nthe QR code at the entrance control.",
                'right_text_color'   => '#FFFFFF',
                'right_text_size'    => 9,
                'cut_line_enabled'   => 1,
                'cut_line_color'     => '#111111',
                'cut_line_gap'       => 60,
            ],
            'left_column' => [
                'x' => 265,
                'y' => 185,
                'width' => 320,
                'height' => 300,
                'font_size' => 12,
                'color' => '#FFFFFF',
                'label_color' => '',
                'vertical_center' => true,
                'label_position' => 'inline',
                'word_wrap' => 'break',
                'line_height' => 35,
            ],
            'right_column' => [
                'x' => 703,
                'y' => 185,
                'width' => 320,
                'height' => 300,
                'font_size' => 12,
                'color' => '#FFFFFF',
                'label_color' => '',
                'vertical_center' => true,
                'label_position' => 'inline',
                'word_wrap' => 'break',
                'line_height' => 35,
            ],
            'qr_code' => [
                'x' => 1238,
                'y' => 190,
                'size' => 270,
            ],
            'ticket_field_order_first' => [],
            'ticket_field_order_second' => [],
            'auto_field_layout' => 1,
            'footer_text' => [
                'enabled'   => 0,
                'text'      => '',
                'font_size' => 12,
                'color'     => '#000000',
                'x'         => 97,
                'width'     => 0,
            ],
        ];
    }

    private function sanitizeFooterText($input)
    {
        $in = is_array($input) ? $input : [];
        return [
            'enabled'   => !empty($in['enabled']) ? 1 : 0,
            'text'      => isset($in['text']) ? wp_kses_post(wp_unslash($in['text'])) : '',
            'font_size' => isset($in['font_size']) ? max(4, intval($in['font_size'])) : 12,
            'color'     => isset($in['color']) ? (sanitize_hex_color($in['color']) ?: '#000000') : '#000000',
            'x'         => isset($in['x']) ? intval($in['x']) : 97,
            'width'     => isset($in['width']) ? intval($in['width']) : 0,
        ];
    }

    /**
     * All ticket field keys + labels available for the reorder UI.
     * Includes module-provided virtual fields (session), default system fields,
     * and user-defined FieldBuilder fields.
     */
    public function getAllTicketFieldOptions()
    {
        // Default system fields. Module-provided virtual fields (e.g. session_*)
        // attach via the filter at the bottom only when their module is active.
        $options = $this->getTicketDefaultFieldOptions();

        // User FieldBuilder fields with ticket enabled
        if (class_exists('\\Alttag\\Registrations\\FieldBuilder')) {
            foreach (\Alttag\Registrations\FieldBuilder::getFields() as $key => $field) {
                if (!empty($field['is_system'])) {
                    continue;
                }
                $ticket_enabled = !empty($field['ticket']['enabled']);
                if (!$ticket_enabled) {
                    continue;
                }
                $label = \Alttag\Registrations\FieldBuilder::getTicketLabel($field);
                $options[$key] = $label;
            }
        }

        return apply_filters('alttag_registrations_ticket_field_order_options', $options);
    }

    public function getTicketDefaultFieldOptions()
    {
        // Order here defines the default flow for unassigned fields in the Ticket
        // Designer reorder UI. First column fields first (personal → contact → address),
        // then second column fields (company → IDs → admin).
        return [
            // First column
            'first_name' => __('First Name', 'alttag-registrations'),
            'last_name' => __('Last Name', 'alttag-registrations'),
            'email' => __('Email', 'alttag-registrations'),
            'phone' => __('Phone', 'alttag-registrations'),
            'street' => __('Street', 'alttag-registrations'),
            'zip' => __('ZIP', 'alttag-registrations'),
            'city' => __('City', 'alttag-registrations'),
            'country' => __('Country', 'alttag-registrations'),
            // Second column
            'company_name' => __('Company Name', 'alttag-registrations'),
            'business_id' => __('Business ID', 'alttag-registrations'),
            'tax_id' => __('Tax ID', 'alttag-registrations'),
            'vat_id' => __('VAT ID', 'alttag-registrations'),
            'variable_symbol' => __('Variable Symbol', 'alttag-registrations'),
            'used_coupons' => __('Used Coupons', 'alttag-registrations'),
            'selected_days' => __('Selected days', 'alttag-registrations'),
        ];
    }

    /**
     * Get current settings for a specific language and optional product
     *
     * @param string|null $language Language code (null = current edit language)
     * @param int $productId Product ID (0 = global settings)
     * @return array Settings array
     */
    public function getSettings($language = null, $productId = 0)
    {
        if ($language === null) {
            $language = $this->getCurrentEditLanguage();
        }

        $defaults = $this->getDefaultSettings();
        $source = ['type' => 'global'];

        if ($productId > 0) {
            $product_settings = $this->getProductSettings($language, $productId, $defaults, $source);
            if ($product_settings !== null) {
                ticket_designer_debug_log('designer_settings.resolve', [
                    'language' => $language,
                    'product_id' => (int) $productId,
                    'source' => $source,
                    'summary' => ticket_designer_debug_settings_summary($product_settings),
                ]);
                return $product_settings;
            }
        }

        $global_settings = $this->getGlobalSettings($language, $defaults, $source);
        ticket_designer_debug_log('designer_settings.resolve', [
            'language' => $language,
            'product_id' => (int) $productId,
            'source' => $source,
            'summary' => ticket_designer_debug_settings_summary($global_settings),
        ]);

        return $global_settings;
    }

    /**
     * Check if a product has custom ticket design enabled
     *
     * @param int $productId Product ID
     * @return bool
     */
    public function isProductDesignEnabled($productId)
    {
        if ($productId <= 0) {
            return false;
        }

        $toggle = get_post_meta($productId, '_alttag_ticket_design_enabled', true);
        if ($toggle === '1') {
            return true;
        }
        if ($toggle === '0') {
            // Admin explicitly disabled the toggle — respect that even if a
            // previously-saved per-language design array is still in meta,
            // otherwise the checkbox snaps back to checked on every save.
            return false;
        }

        // Toggle was never set (legacy products). Fall back to detecting any
        // existing per-language design settings so they keep applying without
        // the admin needing to re-tick the box.
        foreach ($this->getProductMetaKeyCandidates($productId, null) as $meta_key) {
            $settings = get_post_meta($productId, $meta_key, true);
            if (!empty($settings) && is_array($settings)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get settings for a participant's language
     *
     * @param int $participant_id Participant ID
     * @return array Settings array
     */
    public function getSettingsForParticipant($participant_id)
    {
        $state = \Alttag\Registrations\ParticipantState::get($participant_id);
        $language = $state ? $state->language() : \Alttag\Registrations\get_default_language();

        // Check per-product design first
        $product_id = $state ? (int) $state->getMeta('product_id') : 0;
        if ($product_id > 0 && $this->isProductDesignEnabled($product_id)) {
            return $this->getSettings($language, $product_id);
        }

        return $this->getSettings($language);
    }

    public function getResolvedSettingsForParticipant($participantId)
    {
        $defaults = $this->getDefaultSettings();

        if (isset($GLOBALS['ticket_design_preview_settings']) && is_array($GLOBALS['ticket_design_preview_settings'])) {
            return $this->mergeSettings($GLOBALS['ticket_design_preview_settings'], $defaults);
        }

        return $this->getSettingsForParticipant($participantId);
    }

    public function getTicketFieldOrderForParticipant($participantId)
    {
        $settings = $this->getResolvedSettingsForParticipant($participantId);
        return [
            'first_column' => isset($settings['ticket_field_order_first']) && is_array($settings['ticket_field_order_first'])
                ? $settings['ticket_field_order_first']
                : [],
            'second_column' => isset($settings['ticket_field_order_second']) && is_array($settings['ticket_field_order_second'])
                ? $settings['ticket_field_order_second']
                : [],
        ];
    }

    public function getHiddenDefaultTicketFieldsForParticipant($participantId)
    {
        $settings = $this->getResolvedSettingsForParticipant($participantId);
        $hiddenFields = isset($settings['hidden_default_fields']) && is_array($settings['hidden_default_fields'])
            ? $settings['hidden_default_fields']
            : [];

        // Previously this returned only keys present in the legacy hardcoded
        // default-field list, which dropped any FieldBuilder-driven keys
        // (workplace_*, profession, chamber_number, …) the admin had
        // unchecked in the designer — so unchecking them in the UI quietly
        // did nothing. Any sanitised key is now accepted; downstream
        // consumers compare against actual field keys on the rendered
        // ticket, so a typo / stale key just doesn't match anything.
        return array_values(array_filter(array_map('sanitize_key', $hiddenFields)));
    }

    private function getGlobalSettings($language, array $defaults, &$source = null)
    {
        $option_name = $this->getOptionNameForLanguage($language);
        $settings = get_option($option_name, $defaults);
        $source = [
            'type' => 'global_option',
            'option_name' => $option_name,
        ];

        return $this->mergeSettings($settings, $defaults);
    }

    private function getProductSettings($language, $productId, array $defaults, &$source = null)
    {
        foreach ($this->getProductDesignCandidateIds($productId, $language) as $candidate_product_id) {
            if (!$this->isProductDesignEnabled($candidate_product_id)) {
                continue;
            }

            foreach ($this->getProductMetaKeyCandidates($candidate_product_id, $language) as $meta_key) {
                $settings = get_post_meta($candidate_product_id, $meta_key, true);
                if (!empty($settings) && is_array($settings)) {
                    $source = [
                        'type' => 'product_meta',
                        'product_id' => (int) $candidate_product_id,
                        'meta_key' => $meta_key,
                    ];
                    return $this->mergeSettings($settings, $defaults);
                }
            }
        }

        return null;
    }

    private function getProductDesignCandidateIds($productId, $language = null)
    {
        $candidate_ids = [];

        if (function_exists('pll_get_post_translations')) {
            $translations = pll_get_post_translations($productId);
            if (is_array($translations)) {
                if (!empty($language) && !empty($translations[$language])) {
                    $preferred_translation_id = (int) $translations[$language];
                    if ($preferred_translation_id > 0) {
                        $candidate_ids[] = $preferred_translation_id;
                    }
                }

                foreach ($translations as $translation_id) {
                    $translation_id = (int) $translation_id;
                    if ($translation_id > 0 && !in_array($translation_id, $candidate_ids, true)) {
                        $candidate_ids[] = $translation_id;
                    }
                }
            }
        }

        $productId = (int) $productId;
        if ($productId > 0 && !in_array($productId, $candidate_ids, true)) {
            $candidate_ids[] = $productId;
        }

        return $candidate_ids;
    }

    private function getProductMetaKeyCandidates($productId, $language)
    {
        $base_key = '_alttag_ticket_design_settings';
        $meta_keys = [];

        if (!empty($language)) {
            $meta_keys[] = $this->getMetaKeyForLanguage($language);
        }

        $meta_keys[] = $base_key;

        $all_meta_keys = get_post_custom_keys($productId);
        if (is_array($all_meta_keys)) {
            foreach ($all_meta_keys as $meta_key) {
                if (strpos($meta_key, $base_key) !== 0) {
                    continue;
                }

                if (!in_array($meta_key, $meta_keys, true)) {
                    $meta_keys[] = $meta_key;
                }
            }
        }

        return array_values(array_unique($meta_keys));
    }

    private function mergeSettings($settings, array $defaults)
    {
        if (!is_array($settings)) {
            return $defaults;
        }

        $merged = $defaults;
        foreach ($settings as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])) {
                $merged[$key] = $this->mergeSettings($value, $defaults[$key]);
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    /**
     * Render the designer page
     */
    public function renderDesignerPage()
    {
        $current_product_id = $this->getCurrentEditProductId();
        $settings = $this->getSettings(null, $current_product_id);
        $is_multilingual = $this->isMultilingual();
        $available_languages = $this->getAvailableLanguages();
        $current_language = $this->getCurrentEditLanguage();
        $available_products = $this->getAvailableProducts();
        $is_product_design_enabled = $this->isProductDesignEnabled($current_product_id);
        $ticket_default_field_options = $this->getTicketDefaultFieldOptions();
        $all_ticket_field_options = $this->getAllTicketFieldOptions();

        ticket_designer_debug_log('designer_page.render', [
            'query' => [
                'ticket_lang' => $_GET['ticket_lang'] ?? '',
                'ticket_product_id' => $_GET['ticket_product_id'] ?? '',
            ],
            'current_language' => $current_language,
            'current_product_id' => (int) $current_product_id,
            'current_product_language' => $this->getProductLanguage($current_product_id),
            'product_design_enabled' => $is_product_design_enabled,
            'summary' => ticket_designer_debug_settings_summary($settings),
        ]);

        include ALTTAG_REGISTRATIONS_PATH . '/templates/admin/ticket-designer-settings.php';
    }

    /**
     * Save settings
     */
    public function saveSettings()
    {
        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'save_ticket_design_settings')) {
            wp_die(__('Security check failed', 'alttag-registrations'));
        }

        // Verify user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'alttag-registrations'));
        }

        // Get language from POST data
        $language = isset($_POST['ticket_lang']) ? sanitize_text_field($_POST['ticket_lang']) : null;
        if (empty($language)) {
            $language = \Alttag\Registrations\get_default_language();
        }

        // Compute hidden fields: present - visible (both from new unified UI)
        $present = isset($_POST['ticket_present_fields']) && is_array($_POST['ticket_present_fields'])
            ? array_map('sanitize_key', $_POST['ticket_present_fields'])
            : null;
        $visible = isset($_POST['ticket_visible_fields']) && is_array($_POST['ticket_visible_fields'])
            ? array_map('sanitize_key', $_POST['ticket_visible_fields'])
            : [];
        if (is_array($present)) {
            $hidden_from_ui = array_values(array_diff($present, $visible));
        } else {
            // Fallback to legacy hidden_default_fields input
            $hidden_from_ui = $_POST['hidden_default_fields'] ?? [];
        }

        // --- Ticket frame settings (new template body) ---
        $tf_in = isset($_POST['ticket_frame']) && is_array($_POST['ticket_frame']) ? $_POST['ticket_frame'] : [];
        $ticket_frame = [
            'enabled'            => !empty($tf_in['enabled']) ? 1 : 0,
            'height'             => isset($tf_in['height']) ? intval($tf_in['height']) : 438,
            'y'                  => isset($tf_in['y']) ? intval($tf_in['y']) : 150,
            'logo_gap'           => isset($tf_in['logo_gap']) ? max(0, intval($tf_in['logo_gap'])) : 0,
            'border_radius'      => isset($tf_in['border_radius']) ? intval($tf_in['border_radius']) : 26,
            'bg_type'            => in_array(($tf_in['bg_type'] ?? 'gradient'), ['solid', 'gradient', 'image'], true)
                                     ? $tf_in['bg_type'] : 'gradient',
            'bg_color'           => isset($tf_in['bg_color']) ? sanitize_hex_color($tf_in['bg_color']) : '#C479E6',
            'bg_color_2'         => isset($tf_in['bg_color_2']) ? sanitize_hex_color($tf_in['bg_color_2']) : '#54C8EA',
            'gradient_direction' => in_array(($tf_in['gradient_direction'] ?? 'horizontal'), ['horizontal', 'vertical'], true)
                                     ? $tf_in['gradient_direction'] : 'horizontal',
            'bg_image'           => isset($tf_in['bg_image']) ? sanitize_text_field($tf_in['bg_image']) : '',
            'stub_color'         => isset($tf_in['stub_color']) ? sanitize_hex_color($tf_in['stub_color']) : '#9C1C90',
            'stub_width'         => isset($tf_in['stub_width']) ? intval($tf_in['stub_width']) : 107,
            'perf_enabled'       => !empty($tf_in['perf_enabled']) ? 1 : 0,
            'perf_x'             => isset($tf_in['perf_x']) ? intval($tf_in['perf_x']) : 1013,
            'perf_color'         => isset($tf_in['perf_color']) ? sanitize_hex_color($tf_in['perf_color']) : '#0D1A26',
            'right_text'         => isset($tf_in['right_text']) ? sanitize_textarea_field(wp_unslash($tf_in['right_text'])) : '',
            'right_text_color'   => isset($tf_in['right_text_color']) ? sanitize_hex_color($tf_in['right_text_color']) : '#FFFFFF',
            'right_text_size'    => isset($tf_in['right_text_size']) ? intval($tf_in['right_text_size']) : 9,
            'cut_line_enabled'   => !empty($tf_in['cut_line_enabled']) ? 1 : 0,
            'cut_line_color'     => isset($tf_in['cut_line_color']) ? sanitize_hex_color($tf_in['cut_line_color']) : '#111111',
            'cut_line_gap'       => isset($tf_in['cut_line_gap']) ? intval($tf_in['cut_line_gap']) : 60,
        ];

        // Build settings array from POST data
        $settings = [
            'header_image' => isset($_POST['header_image']) ? sanitize_text_field($_POST['header_image']) : '',
            'hidden_default_fields' => $this->sanitizeHiddenDefaultFields($hidden_from_ui),
            'ticket_frame' => $ticket_frame,
            'left_column' => [
                'x' => isset($_POST['left_column_x']) ? intval($_POST['left_column_x']) : 265,
                'y' => isset($_POST['left_column_y']) ? intval($_POST['left_column_y']) : 185,
                'width' => isset($_POST['left_column_width']) ? intval($_POST['left_column_width']) : 320,
                'height' => isset($_POST['left_column_height']) ? intval($_POST['left_column_height']) : 300,
                'font_size' => isset($_POST['left_column_font_size']) ? intval($_POST['left_column_font_size']) : 12,
                'color' => isset($_POST['left_column_color']) ? sanitize_hex_color($_POST['left_column_color']) : '#000000',
                'label_color' => isset($_POST['left_column_label_color']) ? sanitize_hex_color($_POST['left_column_label_color']) : '',
                'vertical_center' => isset($_POST['left_column_vertical_center']),
                'label_position' => $this->sanitizeLabelPosition($_POST['left_column_label_position'] ?? 'inline'),
                'word_wrap' => $this->sanitizeWordWrap($_POST['left_column_word_wrap'] ?? 'break'),
                'line_height' => isset($_POST['left_column_line_height']) ? max(1, intval($_POST['left_column_line_height'])) : 35,
            ],
            'right_column' => [
                'x' => isset($_POST['right_column_x']) ? intval($_POST['right_column_x']) : 703,
                'y' => isset($_POST['right_column_y']) ? intval($_POST['right_column_y']) : 185,
                'width' => isset($_POST['right_column_width']) ? intval($_POST['right_column_width']) : 320,
                'height' => isset($_POST['right_column_height']) ? intval($_POST['right_column_height']) : 300,
                'font_size' => isset($_POST['right_column_font_size']) ? intval($_POST['right_column_font_size']) : 12,
                'color' => isset($_POST['right_column_color']) ? sanitize_hex_color($_POST['right_column_color']) : '#000000',
                'label_color' => isset($_POST['right_column_label_color']) ? sanitize_hex_color($_POST['right_column_label_color']) : '',
                'vertical_center' => isset($_POST['right_column_vertical_center']),
                'label_position' => $this->sanitizeLabelPosition($_POST['right_column_label_position'] ?? 'inline'),
                'word_wrap' => $this->sanitizeWordWrap($_POST['right_column_word_wrap'] ?? 'break'),
                'line_height' => isset($_POST['right_column_line_height']) ? max(1, intval($_POST['right_column_line_height'])) : 35,
            ],
            'qr_code' => [
                'x' => isset($_POST['qr_code_x']) ? intval($_POST['qr_code_x']) : 1238,
                'y' => isset($_POST['qr_code_y']) ? intval($_POST['qr_code_y']) : 190,
                'size' => isset($_POST['qr_code_size']) ? intval($_POST['qr_code_size']) : 300,
            ],
            'content_rows' => $this->parseContentRows($_POST),
            'ticket_field_order_first' => $this->sanitizeTicketFieldOrder($_POST['ticket_field_order_first'] ?? []),
            'ticket_field_order_second' => $this->sanitizeTicketFieldOrder($_POST['ticket_field_order_second'] ?? []),
            'auto_field_layout' => !empty($_POST['auto_field_layout']) ? 1 : 0,
            'footer_text' => $this->sanitizeFooterText($_POST['footer_text'] ?? []),
        ];

        // Check if saving for a specific product
        $product_id = isset($_POST['ticket_product_id']) ? intval($_POST['ticket_product_id']) : 0;

        // Resolve to the correct translation product for the selected language
        if ($product_id > 0) {
            $candidates = $this->getProductDesignCandidateIds($product_id, $language);
            if (!empty($candidates)) {
                $product_id = $candidates[0];
            }
        }

        if ($product_id > 0) {
            // Save per-product settings
            $meta_key = $this->getMetaKeyForLanguage($language);
            update_post_meta($product_id, $meta_key, $settings);

            // Save enabled/disabled state
            $enabled = isset($_POST['ticket_design_enabled']) ? '1' : '0';
            update_post_meta($product_id, '_alttag_ticket_design_enabled', $enabled);
        } else {
            // Save global settings
            $option_name = $this->getOptionNameForLanguage($language);
            update_option($option_name, $settings);
        }

        // Redirect back to settings page with success message
        $redirect_args = [
            'page' => 'ticket-designer',
            'ticket_lang' => $language,
            'message' => 'saved',
        ];
        if ($product_id > 0) {
            $redirect_args['ticket_product_id'] = $product_id;
        }
        wp_redirect(add_query_arg($redirect_args, admin_url('edit.php?post_type=participant')));
        exit;
    }

    /**
     * Parse content rows from POST data
     */
    private function parseContentRows($post_data)
    {
        $rows = [];

        if (!isset($post_data['content_rows']) || !is_array($post_data['content_rows'])) {
            return $rows;
        }

        foreach ($post_data['content_rows'] as $index => $row) {
            $type = isset($row['type']) ? sanitize_text_field($row['type']) : 'text';

            $parsed_row = ['type' => $type];

            // Universal block spacing — every block type has padding_top / padding_bottom
            $parsed_row['padding_top']    = isset($row['padding_top'])    ? max(0, intval($row['padding_top']))    : 5;
            $parsed_row['padding_bottom'] = isset($row['padding_bottom']) ? max(0, intval($row['padding_bottom'])) : 5;

            if ($type === 'text') {
                $parsed_row['text'] = isset($row['text']) ? wp_kses_post(wp_unslash($row['text'])) : '';
                $parsed_row['color'] = isset($row['color']) ? sanitize_hex_color($row['color']) : '#000000';
                $parsed_row['font_size'] = isset($row['font_size']) ? intval($row['font_size']) : 12;
                $parsed_row['width'] = isset($row['width']) ? floatval($row['width']) : 0;
                $parsed_row['x'] = isset($row['x']) ? floatval($row['x']) : 0;
                $parsed_row['y'] = isset($row['y']) ? floatval($row['y']) : 0;
            } elseif ($type === 'image') {
                $parsed_row['image_url'] = isset($row['image_url']) ? sanitize_text_field($row['image_url']) : '';
                $parsed_row['alignment'] = isset($row['alignment']) ? sanitize_text_field($row['alignment']) : 'centered';
                $parsed_row['width'] = isset($row['width']) ? floatval($row['width']) : 0;
                $parsed_row['height'] = isset($row['height']) ? floatval($row['height']) : 0;

                // Y position is supported for both centered and absolute alignment
                $parsed_row['y'] = isset($row['y']) ? floatval($row['y']) : 0;

                // Only parse X if alignment is absolute
                if ($parsed_row['alignment'] === 'absolute') {
                    $parsed_row['x'] = isset($row['x']) ? floatval($row['x']) : 0;
                }
            } elseif ($type === 'logo_row') {
                // Parse individual logo fields (X positions will be calculated automatically)
                $logos = [];
                if (isset($row['logos']) && is_array($row['logos'])) {
                    foreach ($row['logos'] as $logo_index => $logo) {
                        if (isset($logo['image_url']) && !empty($logo['image_url'])) {
                            $logos[] = [
                                'image_url' => sanitize_text_field($logo['image_url']),
                            ];
                        }
                    }
                }
                $parsed_row['logos'] = $logos;
                $parsed_row['y'] = isset($row['y']) ? floatval($row['y']) : 0;
                $parsed_row['width'] = isset($row['width']) ? floatval($row['width']) : 0;
                $parsed_row['height'] = isset($row['height']) ? floatval($row['height']) : 0;
                $parsed_row['spacing'] = isset($row['spacing']) && !empty($row['spacing']) ? floatval($row['spacing']) : null;
            } elseif ($type === 'qr_code') {
                $parsed_row['qr_content'] = isset($row['qr_content']) ? sanitize_text_field($row['qr_content']) : '';
                $parsed_row['x'] = isset($row['x']) ? floatval($row['x']) : 0;
                $parsed_row['y'] = isset($row['y']) ? floatval($row['y']) : 0;
                $parsed_row['size'] = isset($row['size']) ? floatval($row['size']) : 270;

                // Optional frame around the QR
                $parsed_row['border_enabled'] = !empty($row['border_enabled']) ? 1 : 0;
                $parsed_row['border_color']   = isset($row['border_color']) ? sanitize_hex_color($row['border_color']) : '#2B5C63';
                $parsed_row['border_width']   = isset($row['border_width']) ? floatval($row['border_width']) : 4;
                $parsed_row['border_radius']  = isset($row['border_radius']) ? floatval($row['border_radius']) : 20;
                $parsed_row['padding']        = isset($row['padding']) ? floatval($row['padding']) : 20;

                // Optional title pill below the QR
                $parsed_row['title_text']      = isset($row['title_text']) ? sanitize_text_field(wp_unslash($row['title_text'])) : '';
                $parsed_row['title_bg_color']  = isset($row['title_bg_color']) ? sanitize_hex_color($row['title_bg_color']) : '#9C27B0';
                $parsed_row['title_color']     = isset($row['title_color']) ? sanitize_hex_color($row['title_color']) : '#FFFFFF';
                $parsed_row['title_font_size'] = isset($row['title_font_size']) ? intval($row['title_font_size']) : 11;
                $parsed_row['title_height']    = isset($row['title_height']) ? floatval($row['title_height']) : 60;

                // When true, the very next content row renders inline (side by
                // side to the right of this QR) instead of below it.
                $parsed_row['inline_next']     = !empty($row['inline_next']) ? 1 : 0;
                $parsed_row['padding_left']    = isset($row['padding_left']) ? max(0, intval($row['padding_left'])) : 0;
                $parsed_row['padding_right']   = isset($row['padding_right']) ? max(0, intval($row['padding_right'])) : 0;
            } elseif ($type === 'pill') {
                // Title on a colored rounded bar. Supports centered / absolute placement.
                $parsed_row['text']          = isset($row['text']) ? sanitize_text_field(wp_unslash($row['text'])) : '';
                $parsed_row['bg_color']      = isset($row['bg_color']) ? sanitize_hex_color($row['bg_color']) : '#9C27B0';
                $parsed_row['color']         = isset($row['color']) ? sanitize_hex_color($row['color']) : '#FFFFFF';
                $parsed_row['font_size']     = isset($row['font_size']) ? intval($row['font_size']) : 18;
                $parsed_row['width']         = isset($row['width']) ? floatval($row['width']) : 400;
                $parsed_row['height']        = isset($row['height']) ? floatval($row['height']) : 60;
                $parsed_row['border_radius'] = isset($row['border_radius']) ? floatval($row['border_radius']) : 30;
                $parsed_row['alignment']     = isset($row['alignment']) ? sanitize_text_field($row['alignment']) : 'centered';
                $parsed_row['y']             = isset($row['y']) ? floatval($row['y']) : 0;
                if ($parsed_row['alignment'] === 'absolute') {
                    $parsed_row['x'] = isset($row['x']) ? floatval($row['x']) : 0;
                }
            }

            $rows[] = $parsed_row;
        }

        return $rows;
    }

    private function sanitizeTicketFieldOrder($order)
    {
        if (!is_array($order)) {
            return [];
        }
        $allowed = array_keys($this->getAllTicketFieldOptions());
        $clean = [];
        foreach ($order as $key) {
            $key = sanitize_key($key);
            if (in_array($key, $allowed, true) && !in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }
        return $clean;
    }

    private function sanitizeHiddenDefaultFields($hiddenFields)
    {
        if (!is_array($hiddenFields)) {
            return [];
        }

        // Accept any known ticket field key (default, session-virtual, or user FB)
        $allowedKeys = array_keys($this->getAllTicketFieldOptions());

        return array_values(array_intersect(
            array_map('sanitize_key', $hiddenFields),
            $allowedKeys
        ));
    }

    private function sanitizeLabelPosition($value)
    {
        return in_array($value, ['inline', 'above'], true) ? $value : 'inline';
    }

    private function sanitizeWordWrap($value)
    {
        return in_array($value, ['break', 'words'], true) ? $value : 'break';
    }

    /**
     * AJAX handler for preview ticket with current settings
     */
    public function ajaxPreviewTicket()
    {
        check_ajax_referer('ticket_designer_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'alttag-registrations')]);
        }

        $preview_product_id = isset($_POST['ticket_product_id']) ? intval($_POST['ticket_product_id']) : 0;

        // Get participant ID (use first available participant for preview)
        $participant_id = isset($_POST['participant_id']) ? intval($_POST['participant_id']) : 0;

        if (!$participant_id) {
            $participant_id = $this->findPreviewParticipant($preview_product_id);

            if (!$participant_id) {
                // Get first participant
                $participants = get_posts([
                    'post_type' => 'participant',
                    'posts_per_page' => 1,
                    'orderby' => 'post_date',
                    'order' => 'DESC',
                ]);

                if (empty($participants)) {
                    wp_send_json_error(['message' => __('No participants found', 'alttag-registrations')]);
                }

                $participant_id = $participants[0]->ID;
            }
        }

        // Ticket-frame block from AJAX
        $tf_in = isset($_POST['settings']['ticket_frame']) && is_array($_POST['settings']['ticket_frame'])
            ? $_POST['settings']['ticket_frame'] : [];
        $ticket_frame_ajax = [
            'enabled'            => !empty($tf_in['enabled']) ? 1 : 0,
            'height'             => isset($tf_in['height']) ? intval($tf_in['height']) : 438,
            'y'                  => isset($tf_in['y']) ? intval($tf_in['y']) : 150,
            'logo_gap'           => isset($tf_in['logo_gap']) ? max(0, intval($tf_in['logo_gap'])) : 0,
            'border_radius'      => isset($tf_in['border_radius']) ? intval($tf_in['border_radius']) : 26,
            'bg_type'            => in_array(($tf_in['bg_type'] ?? 'gradient'), ['solid', 'gradient', 'image'], true)
                                     ? $tf_in['bg_type'] : 'gradient',
            'bg_color'           => isset($tf_in['bg_color']) ? sanitize_hex_color($tf_in['bg_color']) : '#C479E6',
            'bg_color_2'         => isset($tf_in['bg_color_2']) ? sanitize_hex_color($tf_in['bg_color_2']) : '#54C8EA',
            'gradient_direction' => in_array(($tf_in['gradient_direction'] ?? 'horizontal'), ['horizontal', 'vertical'], true)
                                     ? $tf_in['gradient_direction'] : 'horizontal',
            'bg_image'           => isset($tf_in['bg_image']) ? sanitize_text_field($tf_in['bg_image']) : '',
            'stub_color'         => isset($tf_in['stub_color']) ? sanitize_hex_color($tf_in['stub_color']) : '#9C1C90',
            'stub_width'         => isset($tf_in['stub_width']) ? intval($tf_in['stub_width']) : 107,
            'perf_enabled'       => !empty($tf_in['perf_enabled']) ? 1 : 0,
            'perf_x'             => isset($tf_in['perf_x']) ? intval($tf_in['perf_x']) : 1013,
            'perf_color'         => isset($tf_in['perf_color']) ? sanitize_hex_color($tf_in['perf_color']) : '#0D1A26',
            'right_text'         => isset($tf_in['right_text']) ? sanitize_textarea_field(wp_unslash($tf_in['right_text'])) : '',
            'right_text_color'   => isset($tf_in['right_text_color']) ? sanitize_hex_color($tf_in['right_text_color']) : '#FFFFFF',
            'right_text_size'    => isset($tf_in['right_text_size']) ? intval($tf_in['right_text_size']) : 9,
            'cut_line_enabled'   => !empty($tf_in['cut_line_enabled']) ? 1 : 0,
            'cut_line_color'     => isset($tf_in['cut_line_color']) ? sanitize_hex_color($tf_in['cut_line_color']) : '#111111',
            'cut_line_gap'       => isset($tf_in['cut_line_gap']) ? intval($tf_in['cut_line_gap']) : 60,
        ];

        // Parse settings from AJAX request
        $settings = [
            'header_image' => isset($_POST['settings']['header_image']) ? sanitize_text_field($_POST['settings']['header_image']) : '',
            'hidden_default_fields' => $this->sanitizeHiddenDefaultFields($_POST['settings']['hidden_default_fields'] ?? []),
            'ticket_frame' => $ticket_frame_ajax,
            'left_column' => [
                'x' => isset($_POST['settings']['left_column']['x']) ? intval($_POST['settings']['left_column']['x']) : 265,
                'y' => isset($_POST['settings']['left_column']['y']) ? intval($_POST['settings']['left_column']['y']) : 185,
                'width' => isset($_POST['settings']['left_column']['width']) ? intval($_POST['settings']['left_column']['width']) : 320,
                'height' => isset($_POST['settings']['left_column']['height']) ? intval($_POST['settings']['left_column']['height']) : 300,
                'font_size' => isset($_POST['settings']['left_column']['font_size']) ? intval($_POST['settings']['left_column']['font_size']) : 12,
                'color' => isset($_POST['settings']['left_column']['color']) ? sanitize_hex_color($_POST['settings']['left_column']['color']) : '#000000',
                'label_color' => isset($_POST['settings']['left_column']['label_color']) ? sanitize_hex_color($_POST['settings']['left_column']['label_color']) : '',
                'vertical_center' => !empty($_POST['settings']['left_column']['vertical_center']),
                'label_position' => $this->sanitizeLabelPosition($_POST['settings']['left_column']['label_position'] ?? 'inline'),
                'word_wrap' => $this->sanitizeWordWrap($_POST['settings']['left_column']['word_wrap'] ?? 'break'),
                'line_height' => isset($_POST['settings']['left_column']['line_height']) ? max(1, intval($_POST['settings']['left_column']['line_height'])) : 35,
            ],
            'right_column' => [
                'x' => isset($_POST['settings']['right_column']['x']) ? intval($_POST['settings']['right_column']['x']) : 703,
                'y' => isset($_POST['settings']['right_column']['y']) ? intval($_POST['settings']['right_column']['y']) : 185,
                'width' => isset($_POST['settings']['right_column']['width']) ? intval($_POST['settings']['right_column']['width']) : 320,
                'height' => isset($_POST['settings']['right_column']['height']) ? intval($_POST['settings']['right_column']['height']) : 300,
                'font_size' => isset($_POST['settings']['right_column']['font_size']) ? intval($_POST['settings']['right_column']['font_size']) : 12,
                'color' => isset($_POST['settings']['right_column']['color']) ? sanitize_hex_color($_POST['settings']['right_column']['color']) : '#000000',
                'label_color' => isset($_POST['settings']['right_column']['label_color']) ? sanitize_hex_color($_POST['settings']['right_column']['label_color']) : '',
                'vertical_center' => !empty($_POST['settings']['right_column']['vertical_center']),
                'label_position' => $this->sanitizeLabelPosition($_POST['settings']['right_column']['label_position'] ?? 'inline'),
                'word_wrap' => $this->sanitizeWordWrap($_POST['settings']['right_column']['word_wrap'] ?? 'break'),
                'line_height' => isset($_POST['settings']['right_column']['line_height']) ? max(1, intval($_POST['settings']['right_column']['line_height'])) : 35,
            ],
            'qr_code' => [
                'x' => isset($_POST['settings']['qr_code']['x']) ? intval($_POST['settings']['qr_code']['x']) : 1238,
                'y' => isset($_POST['settings']['qr_code']['y']) ? intval($_POST['settings']['qr_code']['y']) : 190,
                'size' => isset($_POST['settings']['qr_code']['size']) ? intval($_POST['settings']['qr_code']['size']) : 300,
            ],
            'content_rows' => $this->parseContentRows($_POST['settings'] ?? []),
            'ticket_field_order_first' => $this->sanitizeTicketFieldOrder($_POST['settings']['ticket_field_order_first'] ?? []),
            'ticket_field_order_second' => $this->sanitizeTicketFieldOrder($_POST['settings']['ticket_field_order_second'] ?? []),
            'auto_field_layout' => !empty($_POST['settings']['auto_field_layout']) ? 1 : 0,
            'footer_text' => $this->sanitizeFooterText($_POST['settings']['footer_text'] ?? []),
        ];

        ticket_designer_debug_log('designer_preview.ajax_request', [
            'posted_ticket_lang' => isset($_POST['ticket_lang']) ? sanitize_text_field($_POST['ticket_lang']) : '',
            'posted_ticket_product_id' => $preview_product_id,
            'resolved_participant_id' => (int) $participant_id,
            'summary' => ticket_designer_debug_settings_summary($settings),
        ]);

        // Temporarily save settings for preview
        set_transient('ticket_design_preview_' . get_current_user_id(), $settings, 300); // 5 minutes

        // Get the language being edited from the AJAX request
        $preview_language = isset($_POST['ticket_lang']) ? sanitize_text_field($_POST['ticket_lang']) : $this->getCurrentEditLanguage();

        // Generate preview URL with language and product parameters
        $preview_args = [
            'action' => 'preview_ticket_pdf',
            'participant_id' => $participant_id,
            'preview_mode' => 1,
            'preview_lang' => $preview_language,
            '_wpnonce' => wp_create_nonce('preview_ticket_' . $participant_id),
        ];
        if ($preview_product_id > 0) {
            $preview_args['preview_product_id'] = $preview_product_id;
        }
        $preview_url = add_query_arg($preview_args, admin_url('admin-post.php'));

        wp_send_json_success([
            'pdf_url' => $preview_url,
            'participant_id' => $participant_id,
        ]);
    }

    /**
     * AJAX handler to get global ticket settings
     */
    public function ajaxGetGlobalSettings()
    {
        check_ajax_referer('ticket_designer_settings_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'alttag-registrations')]);
        }

        $language = isset($_POST['ticket_lang']) ? sanitize_text_field($_POST['ticket_lang']) : null;
        if (empty($language)) {
            $language = \Alttag\Registrations\get_default_language();
        }

        // Get global settings (productId = 0)
        $settings = $this->getSettings($language, 0);

        wp_send_json_success(['settings' => $settings]);
    }

    private function findPreviewParticipant($productId)
    {
        $candidate_product_ids = $this->getProductDesignCandidateIds((int) $productId, $this->getCurrentEditLanguage());

        if (!empty($candidate_product_ids)) {
            $participants = get_posts([
                'post_type' => 'participant',
                'post_status' => ['publish', 'draft', 'private'],
                'posts_per_page' => 1,
                'orderby' => 'post_date',
                'order' => 'DESC',
                'meta_query' => [
                    [
                        'key' => 'product_id',
                        'value' => array_map('strval', $candidate_product_ids),
                        'compare' => 'IN',
                    ],
                ],
            ]);

            if (!empty($participants)) {
                ticket_designer_debug_log('designer_preview.participant_lookup', [
                    'requested_product_id' => (int) $productId,
                    'candidate_product_ids' => $candidate_product_ids,
                    'selected_participant_id' => (int) $participants[0]->ID,
                ]);
                return (int) $participants[0]->ID;
            }
        }

        ticket_designer_debug_log('designer_preview.participant_lookup', [
            'requested_product_id' => (int) $productId,
            'candidate_product_ids' => $candidate_product_ids,
            'selected_participant_id' => 0,
        ]);

        return 0;
    }
}
