<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

class Settings
{
    const OPTION_KEY = 'alttag_registrations_settings';
    const EMAIL_TEMPLATE_OPTION = 'alttag_registrations_email_template';

    private $settings = null;

    public function registerHooks()
    {
        add_action('admin_menu', [$this, 'addSettingsPage']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    /**
     * Get all settings
     */
    public function getAll()
    {
        if ($this->settings === null) {
            $this->settings = get_option(self::OPTION_KEY, []);
        }
        return $this->settings;
    }

    /**
     * Get a setting value using dot notation (e.g. 'general.org_name')
     *
     * @param string $key Dot-notation key
     * @param mixed $default Default value
     * @return mixed
     */
    public function get($key, $default = '')
    {
        $settings = $this->getAll();
        $parts = explode('.', $key);

        $value = $settings;
        foreach ($parts as $part) {
            if (!is_array($value) || !isset($value[$part])) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /**
     * Static helper to get a translatable setting value.
     * Checks for {key}_{lang} first, falls back to {key}.
     */
    public static function getTranslatable($key, $default = '')
    {
        $settings = get_option(self::OPTION_KEY, []);
        $parts = explode('.', $key);

        $resolve = function ($parts) use ($settings) {
            $value = $settings;
            foreach ($parts as $part) {
                if (!is_array($value) || !isset($value[$part])) {
                    return '';
                }
                $value = $value[$part];
            }
            return $value;
        };

        $lang = function_exists('pll_current_language')
            ? pll_current_language() : '';
        $default_lang = $resolve(explode('.', 'general.default_language')) ?: 'sk';

        if ($lang && $lang !== $default_lang) {
            $localized = $resolve(explode('.', $key . '_' . $lang));
            if (!empty($localized)) {
                return $localized;
            }
        }

        $value = $resolve($parts);
        return !empty($value) ? $value : $default;
    }

    /**
     * Unified getter with fallback chain: product meta → global per-lang → global default.
     *
     * @param string $key Dot-notation key (e.g. 'general.venue')
     * @param int|null $product_id Product ID for per-product override
     * @param string|null $language Language code (auto-detected if null)
     * @return string
     */
    public static function getValue(string $key, ?int $product_id = null, ?string $language = null): string
    {
        $field_def = self::getFieldDefinition($key);

        // 1. Per-product override
        if ($product_id && !empty($field_def['product_override'])) {
            $meta_key = $field_def['product_meta_key'] ?? '_alttag_' . str_replace('.', '_', $key);
            $value = get_post_meta($product_id, $meta_key, true);
            if ($value !== '' && $value !== null && $value !== false) {
                return (string) $value;
            }
        }

        // 2. Global translatable (per language)
        if (!empty($field_def['translatable'])) {
            return self::getTranslatableWithLang($key, $language);
        }

        // 3. Global default
        return (string) self::getRawValue($key, $field_def['default'] ?? '');
    }

    /**
     * Get array value with product fallback (for complex fields like dates, tiers).
     * Checks per-product toggle meta first: if product has custom data, use it.
     * Otherwise falls back to global.
     *
     * @param string $key Dot-notation key (e.g. 'general.available_dates')
     * @param int|null $product_id Product ID
     * @return array
     */
    public static function getArrayValue(string $key, ?int $product_id = null): array
    {
        $field_def = self::getFieldDefinition($key);

        // 1. Per-product override
        if ($product_id && !empty($field_def['product_override'])) {
            $meta_key = $field_def['product_meta_key'] ?? '_alttag_' . str_replace('.', '_', $key);
            $value = get_post_meta($product_id, $meta_key, true);
            if (is_array($value) && !empty($value)) {
                return $value;
            }
        }

        // 2. Global
        $value = self::getRawValue($key, []);
        return is_array($value) ? $value : [];
    }

    /**
     * Get raw setting value without type casting.
     */
    private static function getRawValue(string $key, $default = '')
    {
        $settings = get_option(self::OPTION_KEY, []);
        $parts = explode('.', $key);
        $value = $settings;
        foreach ($parts as $part) {
            if (!is_array($value) || !isset($value[$part])) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /**
     * Get translatable setting with explicit language parameter.
     */
    public static function getTranslatableWithLang(string $key, ?string $language = null): string
    {
        $settings = get_option(self::OPTION_KEY, []);

        $resolve = function ($dotKey) use ($settings) {
            $parts = explode('.', $dotKey);
            $value = $settings;
            foreach ($parts as $part) {
                if (!is_array($value) || !isset($value[$part])) {
                    return '';
                }
                $value = $value[$part];
            }
            return (string) $value;
        };

        $lang = $language ?? (function_exists('pll_current_language') ? pll_current_language() : '');
        $default_lang = $resolve('general.default_language') ?: 'sk';

        if ($lang && $lang !== $default_lang) {
            $localized = $resolve($key . '_' . $lang);
            if ($localized !== '') {
                return $localized;
            }
        }

        $value = $resolve($key);
        return $value;
    }

    /**
     * Get field definition by dot-notation key.
     *
     * @param string $key e.g. 'general.venue' or just 'venue' (searches all tabs)
     * @return array Field definition or empty array
     */
    public static function getFieldDefinition(string $key): array
    {
        $all_fields = self::getTabFields();
        $parts = explode('.', $key, 2);

        // Try exact tab.field lookup
        if (count($parts) === 2) {
            return $all_fields[$parts[0]][$parts[1]] ?? [];
        }

        // Search across all tabs
        foreach ($all_fields as $tab => $fields) {
            if (isset($fields[$key])) {
                return $fields[$key];
            }
        }

        return [];
    }

    /**
     * Get all fields that can be overridden per product.
     *
     * @return array ['tab.field_key' => field_def, ...]
     */
    public static function getProductOverrideFields(): array
    {
        $result = [];
        foreach (self::getTabFields() as $tab => $fields) {
            foreach ($fields as $key => $field) {
                if (!empty($field['product_override'])) {
                    $field['product_meta_key'] = $field['product_meta_key']
                        ?? '_alttag_' . $tab . '_' . $key;
                    $result[$tab . '.' . $key] = $field;
                }
            }
        }
        return $result;
    }

    /**
     * Set a setting value using dot notation
     *
     * @param string $key
     * @param mixed $value
     */
    public function set($key, $value)
    {
        $settings = $this->getAll();
        $parts = explode('.', $key);
        $current = &$settings;

        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $current[$part] = $value;
            } else {
                if (!isset($current[$part]) || !is_array($current[$part])) {
                    $current[$part] = [];
                }
                $current = &$current[$part];
            }
        }

        $this->settings = $settings;
        update_option(self::OPTION_KEY, $settings);
    }

    /**
     * Get a tab's settings
     *
     * @param string $tab Tab key
     * @return array
     */
    public function getTab($tab)
    {
        $settings = $this->getAll();
        return $settings[$tab] ?? [];
    }

    /**
     * Save a tab's settings
     *
     * @param string $tab Tab key
     * @param array $data Tab data
     */
    public function saveTab($tab, $data)
    {
        $settings = $this->getAll();
        $settings[$tab] = $data;
        $this->settings = $settings;
        update_option(self::OPTION_KEY, $settings);
    }

    /**
     * Get available settings tabs
     */
    public static function getTabs()
    {
        $tabs = [
            'general' => __('General', 'alttag-registrations'),
            'modules' => __('Modules', 'alttag-registrations'),
            'checkout' => __('Checkout', 'alttag-registrations'),
            'email' => __('Email', 'alttag-registrations'),
        ];

        // Add tabs for active modules only
        $core = Core::getInstance();
        if ($core && $core->moduleRegistry) {
            foreach ($core->moduleRegistry->getAll() as $module) {
                $tab = $module->getSettingsTab();
                if ($tab && $module->isEnabled() && !isset($tabs[$tab])) {
                    $tabs[$tab] = $module->getName();
                }

                // Modules that return nested tab→fields also create extra tabs
                if ($module->isEnabled()) {
                    foreach ($module->getSettingsFields() as $key => $field) {
                        if (!isset($field['type']) && !isset($tabs[$key])) {
                            $tabs[$key] = $module->getName();
                        }
                    }
                }
            }
        }

        return $tabs;
    }

    /**
     * Get fields definition for each tab
     */
    public static function getTabFields()
    {
        $base = [
            'modules' => [
                // Module toggles auto-generated by ModuleRegistry
            ],
            'general' => [
                'addon_product' => [
                    'label' => __('Add-on product (not a primary ticket)', 'alttag-registrations'),
                    'type' => 'checkbox',
                    'product_override' => true,
                    'product_meta_key' => '_alttag_addon_product',
                    'settings_hidden' => true,
                    'description' => __(
                        'Mark this product as a complementary add-on '
                        . '(e.g. social evening, gala dinner, optional workshop). '
                        . 'Add-on products are excluded when picking the primary '
                        . 'product on an order — the participant, ticket, and '
                        . 'webhook all anchor to the main registration product '
                        . 'even if the add-on was in the cart first.',
                        'alttag-registrations'
                    ),
                ],
                'skip_session_selection' => [
                    'label' => __('Skip session selection (registers for all sessions)', 'alttag-registrations'),
                    'type' => 'checkbox',
                    'product_override' => true,
                    'product_meta_key' => '_alttag_skip_session_selection',
                    'settings_hidden' => true,
                    'description' => __(
                        'For multi-session/full-package tickets where the buyer should '
                        . 'not pick one session at checkout. Disables every '
                        . 'session/day/participant-type selector for this product, and '
                        . 'projects can opt in to custom checkout UIs via the '
                        . '`alttag_registrations_selection_is_active` filter. The '
                        . 'participant is checked in at scan time against any of the '
                        . 'product\'s sessions.',
                        'alttag-registrations'
                    ),
                ],
                'event_map_url' => [
                    'label' => __('Event map URL', 'alttag-registrations'),
                    'type' => 'url',
                    'product_override' => true,
                    'product_meta_key' => '_event_map_url',
                    'description' => __(
                        'Public link to the venue on a map (Google Maps, Apple Maps, …). '
                        . 'Available in templates as {map_url}.',
                        'alttag-registrations'
                    ),
                ],
                'event_program_url' => [
                    'label' => __('Event program URL', 'alttag-registrations'),
                    'type' => 'url',
                    'product_override' => true,
                    'product_meta_key' => '_event_program_url',
                    'description' => __(
                        'Public link to the event programme. '
                        . 'Available in templates as {program_url}.',
                        'alttag-registrations'
                    ),
                ],
                'event_name' => [
                    'label' => __('Event Name', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_event_name',
                ],
                'event_noun' => [
                    'label' => __('Event Noun (singular)', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_event_noun',
                    'description' => __(
                        'The generic word used to refer to the event in templates, '
                        . 'e.g. "podujatie" (Sk) or "event" (En). Available in templates '
                        . 'as {event_noun}. Override per product when a different word '
                        . 'fits (e.g. "stretnutie", "workshop").',
                        'alttag-registrations'
                    ),
                ],
                'event_noun_genitive' => [
                    'label' => __('Event Noun (genitive, singular)', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_event_noun_genitive',
                    'description' => __(
                        'Genitive form of the event noun for sentences like '
                        . '"online stream of the {event_noun_genitive}". '
                        . 'Example: "podujatia" (Sk genitive of "podujatie"). '
                        . 'Falls back to {event_noun} when empty.',
                        'alttag-registrations'
                    ),
                ],
                'event_name_locative' => [
                    'label' => __('Event Name (locative case)', 'alttag-registrations'),
                    'type' => 'text',
                    'description' => __(
                        'Declined event name for sentences like "na X", e.g. "Konferencii Podnikateľ v pluse"',
                        'alttag-registrations'
                    ),
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_event_name_locative',
                ],
                'event_name_accusative' => [
                    'label' => __('Event Name (accusative case)', 'alttag-registrations'),
                    'type' => 'text',
                    'description' => __(
                        'Declined event name for sentences like "Pri vstupe na X",'
                        . ' e.g. "Konferenciu Podnikateľ v pluse"',
                        'alttag-registrations'
                    ),
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_event_name_accusative',
                ],
                'event_date_start' => [
                    'label' => __('Event Start Date', 'alttag-registrations'),
                    'type' => 'text',
                    'description' => 'YYYY-MM-DD',
                    'product_override' => true,
                    'product_meta_key' => '_event_date_start',
                ],
                'event_date_end' => [
                    'label' => __('Event End Date', 'alttag-registrations'),
                    'type' => 'text',
                    'description' => 'YYYY-MM-DD',
                    'product_override' => true,
                    'product_meta_key' => '_event_date_end',
                ],
                'event_time' => [
                    'label' => __('Event Start Time', 'alttag-registrations'),
                    'type' => 'text',
                    'description' => __(
                        'Format: HH:MM (24h), e.g. "09:00". Available in templates as {event_time} '
                        . 'and appended to date displays that opt in (email header, thank-you page).',
                        'alttag-registrations'
                    ),
                    'product_override' => true,
                    'product_meta_key' => '_event_time',
                ],
                'venue' => [
                    'label' => __('Venue', 'alttag-registrations'),
                    'type' => 'text',
                    'description' => __('Venue name, e.g. "Športová hala Junácka"', 'alttag-registrations'),
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_event_venue',
                ],
                'venue_address' => [
                    'label' => __('Venue Address', 'alttag-registrations'),
                    'type' => 'text',
                    'description' => __('Venue address, e.g. "Junácka 6, Bratislava"', 'alttag-registrations'),
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_event_address',
                ],
                'product_group_label' => [
                    'label' => __('Event group select label', 'alttag-registrations'),
                    'type' => 'text',
                    'description' => __(
                        'Label of the checkout select that switches between products of one event group, e.g. "City". Empty falls back to "Location".',
                        'alttag-registrations'
                    ),
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_product_group_label',
                ],
                'venue_locative' => [
                    'label' => __('Venue (locative case)', 'alttag-registrations'),
                    'type' => 'text',
                    'description' => __(
                        'Venue with address in locative, e.g. "Športovej hale Junácka, Junácka 6, Bratislava"',
                        'alttag-registrations'
                    ),
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_event_venue_locative',
                ],
                'max_participants' => [
                    'label' => __('Max participants per order', 'alttag-registrations'),
                    'type' => 'number',
                    'description' => __('Leave empty or 0 to disable', 'alttag-registrations'),
                    'default' => '0',
                    'product_override' => true,
                    'product_meta_key' => '_max_participants',
                ],
                'variable_symbol_prefix' => [
                    'label' => __('Variable symbol prefix', 'alttag-registrations'),
                    'type' => 'text',
                    'description' => __('Prefix for variable symbols, e.g. "SAO2026". Product SKU used if empty.', 'alttag-registrations'),
                    'product_override' => true,
                    'product_meta_key' => '_variable_symbol_prefix',
                ],
                'max_persons_per_day' => [
                    'label' => __('Max persons per day', 'alttag-registrations'),
                    'type' => 'number',
                    'description' => __('Max persons selectable per day. 0 or 1 = no counter.', 'alttag-registrations'),
                    'default' => '0',
                    'product_override' => true,
                    'product_meta_key' => '_max_persons_per_day',
                ],
                'generates_ticket' => [
                    'label' => __('Generate PDF ticket for this product', 'alttag-registrations'),
                    'type' => 'checkbox',
                    'default' => '1',
                    'product_override' => true,
                    'product_meta_key' => '_generates_ticket',
                    'settings_hidden' => true,
                    'description' => __(
                        'Disable for membership/hotel/livestream products that should not produce a ticket.',
                        'alttag-registrations'
                    ),
                ],
                'required_days' => [
                    'label' => __('Days included in this ticket', 'alttag-registrations'),
                    'type' => 'number',
                    'description' => __(
                        'Fixed number of days this ticket covers. The visitor only picks which days and the product price applies. 0 or empty = free choice, priced from Days & Pricing.',
                        'alttag-registrations'
                    ),
                    'default' => '0',
                    'product_override' => true,
                    'product_meta_key' => '_required_days',
                    'settings_hidden' => true,
                ],
                'available_dates' => [
                    'label' => __('Days & Pricing', 'alttag-registrations'),
                    'type' => 'complex',
                    'product_override' => true,
                    'product_meta_key' => '_event_available_dates',
                    'settings_hidden' => true,
                ],
                'pricing_tiers' => [
                    'label' => __('Multi-day discounts', 'alttag-registrations'),
                    'type' => 'complex',
                    'product_override' => true,
                    'product_meta_key' => '_pricing_tiers',
                    'settings_hidden' => true,
                ],
                'org_name' => [
                    'label' => __('Organization Name', 'alttag-registrations'),
                    'type' => 'text',
                    'description' => __('Name of the organizing entity', 'alttag-registrations'),
                    'translatable' => true,
                ],
                'org_team_name' => [
                    'label' => __('Team Name', 'alttag-registrations'),
                    'type' => 'text',
                    'description' => __('Team or department name (used in emails)', 'alttag-registrations'),
                    'translatable' => true,
                ],
                'contact_email' => [
                    'label' => __('Contact Email', 'alttag-registrations'),
                    'type' => 'email',
                    'description' => __('Contact email shown in emails and footer', 'alttag-registrations'),
                ],
                'mail_from' => [
                    'label' => __('Mail From Address', 'alttag-registrations'),
                    'type' => 'email',
                    'description' => __('Email address used as sender', 'alttag-registrations'),
                ],
                'default_language' => [
                    'label' => __('Default Language', 'alttag-registrations'),
                    'type' => 'select',
                    'options' => 'languages',
                    'description' => __('Default language for registrations', 'alttag-registrations'),
                ],
            ],
            'checkout' => [
                'disable_order_notes' => [
                    'label' => __('Disable Order Notes', 'alttag-registrations'),
                    'type' => 'checkbox',
                    'description' => __('Remove the order notes field from checkout', 'alttag-registrations'),
                ],
                'enable_gdpr_consent' => [
                    'label' => __('Enable GDPR Consent', 'alttag-registrations'),
                    'type' => 'checkbox',
                    'description' => __('Show GDPR consent checkbox on checkout', 'alttag-registrations'),
                ],
                'gdpr_url' => [
                    'label' => __('GDPR Policy URL', 'alttag-registrations'),
                    'type' => 'url',
                    'description' => __('Link to GDPR/privacy policy page', 'alttag-registrations'),
                ],
                'enable_terms_consent' => [
                    'label' => __('Enable Terms Consent', 'alttag-registrations'),
                    'type' => 'checkbox',
                    'description' => __('Show terms & conditions checkbox on checkout', 'alttag-registrations'),
                ],
                'terms_url' => [
                    'label' => __('Terms & Conditions URL', 'alttag-registrations'),
                    'type' => 'url',
                    'description' => __('Link to terms & conditions page', 'alttag-registrations'),
                ],
                'skip_email_duplicate_check' => [
                    'label' => __('Skip Email Duplicate Check', 'alttag-registrations'),
                    'type' => 'checkbox',
                    'description' => __('Allow the same email to register multiple times', 'alttag-registrations'),
                    'product_override' => true,
                    'product_meta_key' => '_alttag_skip_email_duplicate_check',
                ],
                // Multi-day duplicate validation is managed by MultiDayModule settings
                'display_invoice_download_link' => [
                    'label' => __('Display Invoice Download Link', 'alttag-registrations'),
                    'type' => 'checkbox',
                    'description' => __('Show the invoice download link on the thank-you page when an invoice exists', 'alttag-registrations'),
                ],
                'move_order_notes_to_billing' => [
                    'label' => __('Move order notes to billing column', 'alttag-registrations'),
                    'type' => 'checkbox',
                    'description' => __(
                        'Render the order-notes field in the billing column instead of the side column',
                        'alttag-registrations'
                    ),
                ],

                'registration_banner_template' => [
                    'label' => __('Registration product banner template', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'description' => __(
                        'Rendered above the billing form. Supports {product_name}. Leave empty to hide.',
                        'alttag-registrations'
                    ),
                ],
                'custom_css' => [
                    'label' => __('Custom checkout CSS', 'alttag-registrations'),
                    'type' => 'textarea',
                    'description' => __(
                        'Extra CSS injected on the checkout page (e.g. style the registration banner).',
                        'alttag-registrations'
                    ),
                ],
                'stripe_primary_color' => [
                    'label' => __('Stripe Elements primary color', 'alttag-registrations'),
                    'type' => 'color',
                    'description' => __('Primary color for Stripe payment form', 'alttag-registrations'),
                ],
                'selection_theme' => [
                    'label' => __('Day / participant-type selector theme', 'alttag-registrations'),
                    'type' => 'select',
                    'options' => [
                        'light' => __('Light (works on white backgrounds)', 'alttag-registrations'),
                        'dark' => __('Dark (overlay on dark backgrounds)', 'alttag-registrations'),
                    ],
                    'default' => 'light',
                    'description' => __(
                        'Visual theme for the multi-day and participant-type selectors on checkout.',
                        'alttag-registrations'
                    ),
                ],
                'invoice_due_days' => [
                    'label' => __('Invoice due days', 'alttag-registrations'),
                    'type' => 'number',
                    'default' => '14',
                    'product_override' => true,
                    'product_meta_key' => '_alttag_invoice_due_days',
                    'description' => __(
                        'Number of days until SuperFaktura invoice is due. Per-product override allowed.',
                        'alttag-registrations'
                    ),
                ],
            ],
            'participants' => [
                // Fields provided by ParticipantFeaturesModule
            ],
            'email' => [
                'primary_color' => [
                    'label' => __('Primary Color', 'alttag-registrations'),
                    'type' => 'color',
                    'default' => '#323232',
                    'description' => __('Primary brand color for emails', 'alttag-registrations'),
                ],
                'accent_color' => [
                    'label' => __('Accent Color', 'alttag-registrations'),
                    'type' => 'color',
                    'default' => '#FFFFFF',
                    'description' => __('Accent/text color for email header', 'alttag-registrations'),
                ],
                'background_color' => [
                    'label' => __('Background Color', 'alttag-registrations'),
                    'type' => 'color',
                    'default' => '#f5f5f5',
                    'description' => __('Email body background color', 'alttag-registrations'),
                ],
                'dark_primary_color' => [
                    'label' => __('Dark Primary Color', 'alttag-registrations'),
                    'type' => 'color',
                    'default' => '#000000',
                    'description' => __('Darker variant of primary color', 'alttag-registrations'),
                ],
                'info_text' => [
                    'label' => __('Info Text', 'alttag-registrations'),
                    'type' => 'textarea',
                    'description' => __('Information text shown in confirmation email (supports {event_name} placeholder)', 'alttag-registrations'),
                ],
                'footer_info_text' => [
                    'label' => __('Footer Info Text', 'alttag-registrations'),
                    'type' => 'textarea',
                    'description' => __(
                        'Footer text for emails (supports {contact_email} placeholder)',
                        'alttag-registrations'
                    ),
                ],
                'custom_css' => [
                    'label' => __('Custom email CSS', 'alttag-registrations'),
                    'type' => 'textarea',
                    'description' => __(
                        'Extra CSS injected into the order-processing email <style> block.',
                        'alttag-registrations'
                    ),
                ],
                'greeting_format' => [
                    'label' => __('Email greeting format', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'product_override' => true,
                    'product_meta_key' => '_alttag_email_greeting_format',
                    'description' => __(
                        'Greeting template, supports {full_name}, {first_name}, {last_name},'
                        . ' {titles_before}, {titles_after}. Per-product override allowed.',
                        'alttag-registrations'
                    ),
                ],
                'inperson_email_title_paid' => [
                    'label' => __('In-person email title (paid)', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'description' => __(
                        'Supports {event_name} and {event_dates} placeholders.',
                        'alttag-registrations'
                    ),
                ],
                'inperson_email_title_unpaid' => [
                    'label' => __('In-person email title (unpaid)', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                ],
                'livestream_email_title_paid' => [
                    'label' => __('Livestream email title (paid)', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                ],
                'livestream_email_title_unpaid' => [
                    'label' => __('Livestream email title (unpaid)', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                ],
                'inperson_event_details' => [
                    'label' => __('In-person event-details template', 'alttag-registrations'),
                    'type' => 'textarea',
                    'translatable' => true,
                    'description' => __(
                        'Supports {event_name}, {event_dates}, {event_location} placeholders.',
                        'alttag-registrations'
                    ),
                ],
                'livestream_event_details' => [
                    'label' => __('Livestream event-details template', 'alttag-registrations'),
                    'type' => 'textarea',
                    'translatable' => true,
                    'description' => __(
                        'Supports {event_name}, {event_dates} placeholders.',
                        'alttag-registrations'
                    ),
                ],
                'livestream_subject_replace_from' => [
                    'label' => __('Livestream subject – text to replace', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                    'description' => __(
                        'Substring removed from participant manual-email subject when participant is livestream.',
                        'alttag-registrations'
                    ),
                ],
                'livestream_subject_replace_to' => [
                    'label' => __('Livestream subject – replacement text', 'alttag-registrations'),
                    'type' => 'text',
                    'translatable' => true,
                ],
            ],
            'livestream' => [
                // Fields provided by LivestreamModule and WebhookImportModule
            ],
            'notifications' => [
                // Fields provided by NotificationModule
            ],
        ];

        // Merge fields contributed by registered modules
        $core = Core::getInstance();
        if ($core && $core->moduleRegistry) {
            $module_fields = $core->moduleRegistry->collectSettingsFields();
            foreach ($module_fields as $tab => $fields) {
                if (!isset($base[$tab])) {
                    $base[$tab] = [];
                }
                $base[$tab] = array_merge($base[$tab], $fields);
            }
        }

        return $base;
    }

    /**
     * Get available languages (from Polylang or defaults)
     */
    public static function getAvailableLanguages()
    {
        if (function_exists('pll_languages_list')) {
            $languages = pll_languages_list(['fields' => 'slug']);
            if (!empty($languages)) {
                $names = pll_languages_list(['fields' => 'name']);
                return array_combine($languages, $names);
            }
        }

        return [
            'sk' => 'Slovenčina',
            'en' => 'English',
            'cs' => 'Čeština',
            'de' => 'Deutsch',
        ];
    }

    // =========================================================================
    // Admin menu and settings registration
    // =========================================================================

    public function addSettingsPage()
    {
        add_submenu_page(
            'edit.php?post_type=participant',
            __('Settings', 'alttag-registrations'),
            __('Settings', 'alttag-registrations'),
            'manage_options',
            'alttag-settings',
            [$this, 'renderSettingsPage']
        );
    }

    public function registerSettings()
    {
        register_setting('alttag_registrations_settings', self::OPTION_KEY, [
            'sanitize_callback' => [$this, 'sanitizeSettings'],
        ]);
    }

    /**
     * Sanitize settings on save
     */
    public function sanitizeSettings($input)
    {
        if (!is_array($input)) {
            return $this->getAll();
        }

        $tab_fields = self::getTabFields();
        $sanitized = $this->getAll();

        $languages = self::getAvailableLanguages();

        foreach ($tab_fields as $tab => $fields) {
            if (!isset($input[$tab])) {
                continue;
            }

            foreach ($fields as $key => $field_def) {
                // Unchecked checkboxes are not sent by the browser,
                // so explicitly set them to false when saving this tab.
                if ($field_def['type'] === 'checkbox' && !array_key_exists($key, $input[$tab] ?? [])) {
                    $sanitized[$tab][$key] = false;
                }

                // Collect all keys to sanitize: main field + per-language variants
                $keys_to_sanitize = [$key];
                if (!empty($field_def['translatable'])) {
                    foreach (array_keys($languages) as $lang_slug) {
                        $keys_to_sanitize[] = $key . '_' . $lang_slug;
                    }
                }

                foreach ($keys_to_sanitize as $sanitize_key) {
                    if (!array_key_exists($sanitize_key, $input[$tab])) {
                        continue;
                    }
                    $value = $input[$tab][$sanitize_key] ?? '';

                    switch ($field_def['type']) {
                        case 'checkbox':
                            $sanitized[$tab][$sanitize_key] = !empty($value);
                            break;
                        case 'email':
                            $sanitized[$tab][$sanitize_key] = sanitize_email($value);
                            break;
                        case 'url':
                            $sanitized[$tab][$sanitize_key] = esc_url_raw($value);
                            break;
                        case 'number':
                            $sanitized[$tab][$sanitize_key] = absint($value);
                            break;
                        case 'color':
                            $sanitized[$tab][$sanitize_key] = sanitize_hex_color($value) ?: ($field_def['default'] ?? '');
                            break;
                        case 'textarea':
                            $sanitized[$tab][$sanitize_key] = wp_kses_post($value);
                            break;
                        default:
                            $sanitized[$tab][$sanitize_key] = sanitize_text_field($value);
                            break;
                    }
                }
            }
        }

        $this->settings = $sanitized;
        return $sanitized;
    }

    /**
     * Render the settings page
     */
    public function renderSettingsPage()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tabs = self::getTabs();
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

        if (!isset($tabs[$current_tab])) {
            $current_tab = 'general';
        }

        $tab_fields = self::getTabFields();
        $settings = $this->getAll();

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Registration Settings', 'alttag-registrations'); ?></h1>
            <?php
            if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
                echo '<div class="notice notice-success is-dismissible"><p>' .
                    esc_html__('Settings saved.', 'alttag-registrations') .
                    '</p></div>';
            }
            ?>

            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab_key => $tab_label) : ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'alttag-settings', 'tab' => $tab_key], admin_url('edit.php?post_type=participant'))); ?>"
                       class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab_label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form method="post" action="options.php">
                <?php settings_fields('alttag_registrations_settings'); ?>

                <table class="form-table">
                    <?php
                    $is_multilingual = RegistrationContext::current()->isMultilingual();
                    $default_lang = $this->get('general.default_language', 'sk');
                    $languages = $is_multilingual ? self::getAvailableLanguages() : [];

                    if (isset($tab_fields[$current_tab])) {
                        foreach ($tab_fields[$current_tab] as $key => $field) {
                            // Skip fields hidden from settings UI (e.g. complex product-only fields)
                            if (!empty($field['settings_hidden'])) {
                                continue;
                            }
                            $value = $settings[$current_tab][$key] ?? ($field['default'] ?? '');
                            $name = self::OPTION_KEY . '[' . $current_tab . '][' . $key . ']';
                            $id = 'alttag_' . $current_tab . '_' . $key;
                            ?>
                            <tr>
                                <th scope="row">
                                    <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($field['label']); ?></label>
                                </th>
                                <td>
                                    <?php $this->renderField($field, $name, $id, $value); ?>
                                    <?php if (!empty($field['description'])) : ?>
                                        <p class="description"><?php echo esc_html($field['description']); ?></p>
                                    <?php endif; ?>

                                    <?php
                                    // Render per-language fields for translatable fields
                                    if (!empty($field['translatable']) && $is_multilingual) {
                                        foreach ($languages as $lang_slug => $lang_name) {
                                            if ($lang_slug === $default_lang) {
                                                continue;
                                            }
                                            $lang_key = $key . '_' . $lang_slug;
                                            $lang_value = $settings[$current_tab][$lang_key] ?? '';
                                            $lang_name_attr = self::OPTION_KEY . '[' . $current_tab . '][' . $lang_key . ']';
                                            $lang_id = $id . '_' . $lang_slug;
                                            ?>
                                            <div style="margin-top: 8px;">
                                                <label for="<?php echo esc_attr($lang_id); ?>">
                                                    <strong><?php echo esc_html(strtoupper($lang_slug)); ?>:</strong>
                                                </label>
                                                <input type="text" name="<?php echo esc_attr($lang_name_attr); ?>"
                                                       id="<?php echo esc_attr($lang_id); ?>"
                                                       value="<?php echo esc_attr($lang_value); ?>"
                                                       class="regular-text"
                                                       placeholder="<?php echo esc_attr($value); ?>" />
                                            </div>
                                            <?php
                                        }
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php
                        }
                    }
                    ?>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render a single field
     */
    private function renderField($field, $name, $id, $value)
    {
        switch ($field['type']) {
            case 'text':
            case 'email':
            case 'url':
            case 'number':
                printf(
                    '<input type="%s" name="%s" id="%s" value="%s" class="regular-text" />',
                    esc_attr($field['type']),
                    esc_attr($name),
                    esc_attr($id),
                    esc_attr($value)
                );
                break;

            case 'color':
                printf(
                    '<input type="color" name="%s" id="%s" value="%s" />',
                    esc_attr($name),
                    esc_attr($id),
                    esc_attr($value ?: ($field['default'] ?? '#000000'))
                );
                break;

            case 'checkbox':
                printf(
                    '<input type="hidden" name="%s" value="" /><input type="checkbox" name="%s" id="%s" value="1" %s />',
                    esc_attr($name),
                    esc_attr($name),
                    esc_attr($id),
                    checked($value, true, false)
                );
                break;

            case 'textarea':
                printf(
                    '<textarea name="%s" id="%s" rows="4" class="large-text">%s</textarea>',
                    esc_attr($name),
                    esc_attr($id),
                    esc_textarea($value)
                );
                break;

            case 'select':
                $options = [];
                if ($field['options'] === 'languages') {
                    $options = self::getAvailableLanguages();
                } elseif (is_array($field['options'])) {
                    $options = $field['options'];
                }

                printf('<select name="%s" id="%s">', esc_attr($name), esc_attr($id));
                foreach ($options as $opt_value => $opt_label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($opt_value),
                        selected($value, $opt_value, false),
                        esc_html($opt_label)
                    );
                }
                echo '</select>';
                break;
        }
    }

    // =========================================================================
    // Email template methods (backward compatibility)
    // =========================================================================

    public function getEmailTemplate($order)
    {
        $template = file_get_contents(ALTTAG_REGISTRATIONS_PATH . '/templates/order-processing-email.php');
        return apply_filters('alttag_registrations_default_email_template', $template, $order);
    }

    public function getEmailSubject($order, $has_invoice = true)
    {
        // Establish product context from the order so get_event_name_with_year()
        // resolves against the order's product meta (_event_name) instead of
        // falling through to the literal "Event" placeholder. Background email
        // sends have no cart context, so without this every subject reads
        // "Your ticket for Event YYYY".
        $hadCtx = false;
        if ($order instanceof \WC_Order) {
            RegistrationContext::forOrder($order);
            $hadCtx = true;
        }

        try {
            return $this->buildEmailSubject($order, $has_invoice);
        } finally {
            if ($hadCtx) {
                RegistrationContext::reset();
            }
        }
    }

    private function buildEmailSubject($order, $has_invoice)
    {
        // Compose "{event_noun} {event_name_with_year}" so subjects read e.g.
        // "Vstupenka a zaplatená faktúra za podujatie Vesmírna univerzita 2026".
        // The translatable sprintf below keeps its single %s — we just feed
        // it the prefixed event noun + name.
        $event_noun = get_event_noun();
        $event_name = get_event_name_with_year();
        if ($event_noun !== '') {
            $event_name = trim($event_noun . ' ' . $event_name);
        }

        if (!RegistrationContext::current()->hasInvoicing()) {
            $has_invoice = false;
        }

        if (!$order) {
            if ($has_invoice) {
                $subject = sprintf(__('Ticket and invoice for %s', 'alttag-registrations'), $event_name);
            } else {
                $subject = sprintf(__('Your ticket for %s', 'alttag-registrations'), $event_name);
            }
            return apply_filters('alttag_registrations_default_email_subject', $subject, $order);
        }

        $order_status = $order->get_status();
        $is_livestream_only = order_has_only_livestream_product($order);

        if ($is_livestream_only) {
            if ($has_invoice) {
                $text_paid = sprintf(__('Paid invoice for livestream %s', 'alttag-registrations'), $event_name);
                $text_unpaid = sprintf(__('Unpaid invoice for livestream %s', 'alttag-registrations'), $event_name);
            } else {
                $subject = sprintf(__('Registration confirmation for livestream %s', 'alttag-registrations'), $event_name);
                return apply_filters('alttag_registrations_default_email_subject', $subject, $order);
            }
        } elseif (!$has_invoice) {
            $subject = sprintf(__('Your ticket for %s', 'alttag-registrations'), $event_name);
            return apply_filters('alttag_registrations_default_email_subject', $subject, $order);
        } else {
            $text_paid = sprintf(__('Ticket and paid Invoice for %s', 'alttag-registrations'), $event_name);
            $text_unpaid = sprintf(__('Ticket and unpaid invoice for %s', 'alttag-registrations'), $event_name);
        }

        $subject = $order_status === 'completed' ? $text_paid : $text_unpaid;
        return apply_filters('alttag_registrations_default_email_subject', $subject, $order);
    }
}
