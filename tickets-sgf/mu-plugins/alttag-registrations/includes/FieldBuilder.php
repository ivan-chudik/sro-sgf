<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Field Builder - manages custom field definitions stored in DB.
 * Replaces per-project FieldDefinitions classes with a centralized,
 * admin-configurable system.
 */
class FieldBuilder
{
    const OPTION_KEY = 'alttag_registrations_fields';

    private static $fields_cache = null;

    /**
     * Get all field definitions (from DB, with filter override)
     *
     * @return array
     */
    public static function getFields()
    {
        if (self::$fields_cache !== null) {
            return self::$fields_cache;
        }

        $fields = get_option(self::OPTION_KEY, null);

        if ($fields === null || !is_array($fields)) {
            $fields = self::getDefaultFields();
            update_option(self::OPTION_KEY, $fields);
        }

        // Inject SuperFaktúra fields when plugin is active
        if (self::isSuperfakturaActive()) {
            foreach (self::getSuperfakturaSystemFields() as $key => $sf_field) {
                if (!isset($fields[$key])) {
                    $fields[$key] = $sf_field;
                }
            }
        }

        // Participant identity fields are always required — every ticket-flow
        // needs a name and a deliverable inbox (QR + invoice go there). We keep
        // the admin UI toggle for other system fields but force `required = true`
        // on first_name / last_name / email regardless of stored config so the
        // site can never accidentally ship them as "(optional)".
        foreach (['first_name', 'last_name', 'email'] as $_always_required) {
            if (isset($fields[$_always_required]) && is_array($fields[$_always_required])) {
                $fields[$_always_required]['required'] = true;
            }
        }

        self::$fields_cache = apply_filters('alttag_registrations_field_definitions', $fields);
        return self::$fields_cache;
    }

    /**
     * Save field definitions to DB
     *
     * @param array $fields
     */
    public static function saveFields($fields)
    {
        self::$fields_cache = null;
        update_option(self::OPTION_KEY, $fields);
    }

    /**
     * Clear the in-memory cache
     */
    public static function clearCache()
    {
        self::$fields_cache = null;
    }

    /**
     * Get a single field by key
     *
     * @param string $key
     * @return array|null
     */
    public static function getField($key)
    {
        $fields = self::getFields();
        return $fields[$key] ?? null;
    }

    /**
     * Get all field keys
     *
     * @return array
     */
    public static function getFieldKeys()
    {
        return array_keys(self::getFields());
    }

    /**
     * Get available languages from Polylang or defaults
     *
     * @return array ['sk' => 'Slovenčina', 'en' => 'English', ...]
     */
    public static function getAvailableLanguages()
    {
        return Settings::getAvailableLanguages();
    }

    /**
     * Get the label for a field in the current language
     *
     * @param array $field_def
     * @param string|null $lang Language code, null for current
     * @return string
     */
    public static function getFieldLabel($field_def, $lang = null)
    {
        if ($lang === null) {
            $lang = get_current_language();
        }

        // New multi-lang format: labels => ['sk' => 'Meno', 'en' => 'First Name']
        if (isset($field_def['labels']) && is_array($field_def['labels'])) {
            if (isset($field_def['labels'][$lang])) {
                return $field_def['labels'][$lang];
            }
            // Fallback to first available
            $first = reset($field_def['labels']);
            return $first ?: '';
        }

        // Legacy single-label format
        return $field_def['label'] ?? '';
    }

    /**
     * Whether a checkbox / yes_no value counts as "on".
     *
     * Checkbox values get persisted under several conventions depending on
     * which code path wrote them (FB AJAX toggle saves "yes", WC default
     * forms save "1", REST imports may pass true) and some readers cast the
     * meta to a real bool on the way out (Participant\Manager::
     * getParticipantDetails does this for every checkbox field). Every
     * Ano/Nie renderer has to accept the whole set or the same participant
     * reads differently in the list table, the ticket and the export.
     *
     * @param mixed $value
     * @return bool
     */
    public static function isTruthyValue($value)
    {
        return in_array($value, ['1', 1, true, 'yes', 'true', 'on'], true);
    }

    /**
     * Get the ticket label for a field
     *
     * @param array $field_def
     * @param string|null $lang
     * @return string
     */
    public static function getTicketLabel($field_def, $lang = null)
    {
        if ($lang === null) {
            $lang = get_current_language();
        }

        if (isset($field_def['ticket']['labels']) && is_array($field_def['ticket']['labels'])) {
            if (isset($field_def['ticket']['labels'][$lang])) {
                return $field_def['ticket']['labels'][$lang];
            }
            $first = reset($field_def['ticket']['labels']);
            return $first ?: '';
        }

        // Legacy format
        if (isset($field_def['ticket_label'])) {
            return $field_def['ticket_label'];
        }

        return self::getFieldLabel($field_def, $lang);
    }

    // =========================================================================
    // Context-specific field getters (same API as old FieldDefinitions)
    // =========================================================================

    /**
     * Check if field should be displayed based on conditions
     *
     * @param array $field_def
     * @return bool
     */
    public static function shouldDisplayField($field_def)
    {
        $conditions = $field_def['display_conditions'] ?? [];

        $cart = RegistrationContext::current()->cart();

        // Per-product visibility: if 'product_ids' is set, field is only shown when
        // cart contains at least one of those products. Strict AND check.
        if (isset($conditions['product_ids']) && !empty($conditions['product_ids'])) {
            $allowed_ids = self::parseProductIds($conditions['product_ids']);
            if (!empty($allowed_ids)) {
                $cart_ids = array_map('intval', $cart->productIds ?? []);
                if (empty(array_intersect($allowed_ids, $cart_ids))) {
                    return false;
                }
            }
        }

        // Per-product hide: if 'exclude_product_ids' is set, field is HIDDEN when
        // cart contains any of those products. Useful for system fields you want
        // to suppress on specific events that have their own custom fields.
        if (isset($conditions['exclude_product_ids']) && !empty($conditions['exclude_product_ids'])) {
            $excluded_ids = self::parseProductIds($conditions['exclude_product_ids']);
            if (!empty($excluded_ids)) {
                $cart_ids = array_map('intval', $cart->productIds ?? []);
                if (!empty(array_intersect($excluded_ids, $cart_ids))) {
                    return false;
                }
            }
        }

        // Legacy flat conditions (inperson_attendance, online_attendance) — OR logic
        $flat_conditions = array_filter($conditions, 'is_string');

        if (empty($flat_conditions)) {
            return true;
        }

        $is_livestream = $cart->hasLivestream;
        $is_inperson = $cart->hasInperson;

        foreach ($flat_conditions as $condition) {
            switch ($condition) {
                case 'inperson_attendance':
                    if ($is_inperson) {
                        return true;
                    }
                    break;
                case 'online_attendance':
                    if ($is_livestream) {
                        return true;
                    }
                    break;
            }
        }

        return false;
    }

    /**
     * Parse product_ids condition value into an array of int IDs.
     * Accepts: array of ints/strings, or comma-separated string.
     */
    private static function parseProductIds($raw)
    {
        if (is_array($raw)) {
            return array_filter(array_map('intval', $raw));
        }
        if (is_string($raw)) {
            $parts = array_map('trim', explode(',', $raw));
            return array_filter(array_map('intval', $parts));
        }
        return [];
    }

    /**
     * Is this a checkout-visible field?
     */
    private static function isCheckoutField($field_def)
    {
        // ticket_only fields are never shown on checkout
        if (!empty($field_def['ticket_only'])) {
            return false;
        }

        // SuperFaktúra fields are rendered by the SF plugin, not FieldBuilder
        $sf_keys = ['company_name', 'wi_as_company', 'company_wi_id', 'company_wi_tax', 'company_wi_vat'];
        $key = $field_def['key'] ?? '';
        $key_without_prefix = str_replace('billing_', '', $key);
        if (in_array($key_without_prefix, $sf_keys) || in_array($key, $sf_keys)) {
            return false;
        }

        // New contexts format
        if (isset($field_def['contexts'])) {
            if (is_array($field_def['contexts'])) {
                return in_array('checkout', $field_def['contexts']);
            }
        }

        return true;
    }

    /**
     * Get checkout-visible field definitions
     *
     * @return array
     */
    public static function getCheckoutFieldDefinitions()
    {
        $checkout_fields = [];

        foreach (self::getFields() as $field_id => $field_def) {
            if (!self::isCheckoutField($field_def)) {
                continue;
            }

            if (!self::shouldDisplayField($field_def)) {
                continue;
            }

            $checkout_fields[$field_id] = $field_def;
        }

        return $checkout_fields;
    }

    /**
     * Get fields for checkout form
     *
     * @return array WooCommerce checkout fields format
     */
    public static function getCheckoutFields()
    {
        $fields = [];

        foreach (self::getCheckoutFieldDefinitions() as $field_id => $field_def) {
            $checkout_field = [
                'type' => $field_def['type'],
                'label' => self::getFieldLabel($field_def),
                'required' => $field_def['required'] ?? false,
                'class' => $field_def['class'] ?? ['form-row-wide'],
                'priority' => $field_def['priority'] ?? 99,
            ];

            if ($field_def['type'] === 'select' && isset($field_def['options'])) {
                $checkout_field['options'] = $field_def['options'];
            }

            if (isset($field_def['description'])) {
                $checkout_field['description'] = $field_def['description'];
            }

            $fields[$field_def['key']] = $checkout_field;
        }

        return $fields;
    }

    /**
     * Get fields for admin billing section
     *
     * @return array
     */
    public static function getAdminBillingFields()
    {
        $fields = [];
        $field_definitions = self::getFields();

        // Sort by priority before building admin fields
        uasort($field_definitions, function ($a, $b) {
            return ($a['priority'] ?? 99) - ($b['priority'] ?? 99);
        });

        $skip_in_admin = self::adminManagedElsewhere();

        foreach ($field_definitions as $field_id => $field_def) {
            if (!empty($field_def['ticket_only'])) {
                continue;
            }

            if (in_array($field_id, $skip_in_admin)) {
                continue;
            }

            $admin_field = [
                'label' => $field_def['admin_label'] ?? self::getFieldLabel($field_def),
                'show' => true,
                'type' => $field_def['admin_type'] ?? $field_def['type'],
                'class' => $field_def['admin_class'] ?? 'short',
            ];

            if (isset($field_def['options'])) {
                $admin_field['options'] = $field_def['options'];
            }

            $fields[str_replace('billing_', '', $field_def['key'])] = $admin_field;
        }

        return $fields;
    }

    /**
     * Get fields for email display
     *
     * @param \WC_Order $order
     * @return array
     */
    public static function getEmailFields($order)
    {
        $fields = [];
        $field_definitions = self::getFields();

        foreach ($field_definitions as $field_id => $field_def) {
            if (!empty($field_def['ticket_only'])) {
                continue;
            }

            $value = $order->get_meta($field_def['key']);
            if (!$value && $value !== '0') {
                continue;
            }

            $save_format = $field_def['save_format'] ?? null;
            if ($save_format === 'yes_no') {
                $value = self::isTruthyValue($value) ? 'Áno' : 'Nie';
            }

            $label = $field_def['email_label']
                ?? $field_def['admin_label']
                ?? self::getFieldLabel($field_def);

            $fields[$field_id] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        return $fields;
    }

    /**
     * Build a unified, priority-sorted list of rows for the order email
     * "registration details" section.
     *
     * System fields (first_name, email, …) are built from $values.
     * User-defined FieldBuilder fields are read from order / participant meta.
     * Everything is merged and sorted by FieldBuilder priority so the admin
     * controls email field order through the same drag-and-drop UI.
     *
     * @param array    $values        Template-level values (first_name, last_name, email, phone, …)
     * @param \WC_Order|null $order   The order object
     * @param int|null $participantId Participant post ID
     * @param bool     $hideVs        Per-product hide variable symbol flag
     * @return array   [['label' => string, 'value' => string], …]
     */
    public static function getEmailDetailRows(array $values, $order = null, $participantId = null, $hideVs = false)
    {
        $fields = self::getFields();
        $rows = [];

        // Helper: check if a system field should show (has 'email' in contexts)
        $hasEmailCtx = function ($key) use ($fields) {
            $f = $fields[$key] ?? null;
            if (!$f) {
                return true;
            }
            $ctx = $f['contexts'] ?? [];
            return !is_array($ctx) || in_array('email', $ctx, true);
        };

        // Helper: get email priority (email_priority overrides priority)
        $pri = function ($key, $default = 99) use ($fields) {
            $f = $fields[$key] ?? [];
            return $f['email_priority'] ?? $f['priority'] ?? $default;
        };

        // --- System fields (combined / special-case) ---

        // Name: first_name + last_name combined
        $name = trim(($values['first_name'] ?? '') . ' ' . ($values['last_name'] ?? ''));
        if ($name && $hasEmailCtx('first_name')) {
            $rows[] = [
                'priority' => $pri('first_name', 10),
                'label' => __('Name', 'alttag-registrations'),
                'value' => $name,
                'field_id' => 'first_name',
            ];
        }

        // Simple system fields
        $simple = [
            'email' => ['key' => 'email', 'label' => __('Email', 'alttag-registrations'), 'default_pri' => 30],
            'phone' => ['key' => 'phone', 'label' => __('Phone', 'alttag-registrations'), 'default_pri' => 40],
        ];
        foreach ($simple as $id => $cfg) {
            if (!empty($values[$id]) && $hasEmailCtx($id)) {
                $rows[] = [
                    'priority' => $pri($id, $cfg['default_pri']),
                    'label' => $cfg['label'],
                    'value' => $values[$id],
                    'field_id' => $id,
                ];
            }
        }

        // Company (billing_company via company_name FB field)
        if (!empty($values['company_name']) && $hasEmailCtx('company_name')) {
            $rows[] = [
                'priority' => $pri('company_name', 90),
                'label' => __('Company', 'alttag-registrations'),
                'value' => $values['company_name'],
                'field_id' => 'company_name',
            ];
        }

        // Address: street + city + zip + country combined
        $address_parts = array_filter([
            $values['street'] ?? '', $values['city'] ?? '',
            $values['zip'] ?? '', $values['country_name'] ?? '',
        ]);
        if (!empty($address_parts) && $hasEmailCtx('street')) {
            $rows[] = [
                'priority' => $pri('street', 50),
                'label' => __('Address', 'alttag-registrations'),
                'value' => implode(', ', $address_parts),
                'field_id' => 'street',
            ];
        }

        // Business/Tax/VAT IDs (SuperFaktúra — not in FB, fixed priority after company)
        $ids = [
            ['val' => 'business_id', 'label' => __('Business ID', 'alttag-registrations'), 'pri' => 91],
            ['val' => 'vat_id', 'label' => __('VAT ID', 'alttag-registrations'), 'pri' => 92],
            ['val' => 'tax_id', 'label' => __('Tax ID', 'alttag-registrations'), 'pri' => 93],
        ];
        foreach ($ids as $id) {
            if (!empty($values[$id['val']])) {
                $rows[] = [
                    'priority' => $id['pri'],
                    'label' => $id['label'],
                    'value' => $values[$id['val']],
                    'field_id' => $id['val'],
                ];
            }
        }

        // Variable symbol (respect per-product hide)
        if (!empty($values['variable_symbol']) && !$hideVs && $hasEmailCtx('variable_symbol')) {
            $rows[] = [
                'priority' => $pri('variable_symbol', 100),
                'label' => __('Variable symbol', 'alttag-registrations'),
                'value' => $values['variable_symbol'],
                'field_id' => 'variable_symbol',
            ];
        }

        // --- User-defined FieldBuilder fields (non-system, email context) ---
        // Use email_priority when available, fall back to regular priority
        uasort($fields, function ($a, $b) {
            $ap = $a['email_priority'] ?? $a['priority'] ?? 99;
            $bp = $b['email_priority'] ?? $b['priority'] ?? 99;
            return $ap <=> $bp;
        });

        foreach ($fields as $fieldId => $fieldDef) {
            if (!empty($fieldDef['is_system'])) {
                continue;
            }
            if (!empty($fieldDef['ticket_only'])) {
                continue;
            }
            $ctx = $fieldDef['contexts'] ?? [];
            if (!is_array($ctx) || !in_array('email', $ctx, true)) {
                continue;
            }

            $key = $fieldDef['key'] ?? $fieldId;
            $value = '';
            if ($participantId) {
                $value = get_post_meta($participantId, $key, true);
            }
            if (($value === '' || $value === null || $value === false) && $order) {
                $value = $order->get_meta($key);
            }
            if ($value === '' || $value === null || $value === false) {
                continue;
            }

            // Resolve select / multiselect option labels — for multi-value
            // fields each stored value gets mapped to its label, so the email
            // shows "Topic A, Topic B" instead of raw "topic_a, topic_b".
            $fieldType = $fieldDef['type'] ?? '';
            $hasOptions = is_array($fieldDef['options'] ?? null);
            if ($hasOptions && in_array($fieldType, ['select', 'multiselect'], true)) {
                if (is_array($value)) {
                    $value = array_map(function ($v) use ($fieldDef) {
                        $key = (string) $v;
                        return $fieldDef['options'][$key] ?? $v;
                    }, $value);
                } elseif (isset($fieldDef['options'][(string) $value])) {
                    $value = $fieldDef['options'][(string) $value];
                }
            }
            if (is_array($value)) {
                $value = implode(', ', array_filter(array_map('strval', $value)));
            }

            // Checkbox / yes_no booleans render as "Áno" / "Nie" so the email
            // doesn't leak raw storage values like "yes" or "1".
            $saveFormat = $fieldDef['save_format'] ?? null;
            if ($saveFormat === 'yes_no' || $saveFormat === 'checkbox' || $fieldType === 'checkbox') {
                $isTrue = in_array($value, ['1', 1, true, 'yes', 'true', 'on'], true);
                $value = $isTrue ? __('Yes', 'alttag-registrations') : __('No', 'alttag-registrations');
            }

            $rows[] = [
                'priority' => $fieldDef['email_priority'] ?? $fieldDef['priority'] ?? 99,
                'label' => self::getFieldLabel($fieldDef),
                'value' => (string) $value,
                'field_id' => (string) $fieldId,
            ];
        }

        // --- Order comments (always last) ---
        $comments = '';
        if ($order) {
            $comments = $order->get_customer_note();
        }
        if (empty($comments) && isset($values['order_comments'])) {
            $comments = $values['order_comments'];
        }
        if (!empty($comments)) {
            $rows[] = ['priority' => 9999, 'label' => __('Order notes', 'alttag-registrations'), 'value' => $comments, 'nl2br' => true];
        }

        usort($rows, function ($a, $b) {
            return ($a['priority'] ?? 99) <=> ($b['priority'] ?? 99);
        });

        return apply_filters(
            'alttag_registrations_email_detail_rows',
            $rows,
            $values,
            $order,
            $participantId
        );
    }

    /**
     * Get verification section fields (sorted by priority)
     *
     * @return array Field IDs in priority order
     */
    public static function getVerificationFields()
    {
        $field_definitions = self::getFields();

        uasort($field_definitions, function ($a, $b) {
            return ($a['priority'] ?? 999) <=> ($b['priority'] ?? 999);
        });

        $verification_fields = [];
        foreach ($field_definitions as $field_id => $field_def) {
            if (!empty($field_def['ticket_only'])) {
                continue;
            }
            $verification_fields[] = $field_id;
        }

        return $verification_fields;
    }

    /**
     * Get customer data fields with meta keys
     *
     * @return array
     */
    public static function getCustomerDataFields()
    {
        $field_definitions = self::getFields();
        $customer_fields = [];

        foreach ($field_definitions as $field_id => $field_def) {
            $customer_fields[$field_id] = $field_def['key'];
        }

        return $customer_fields;
    }

    /**
     * Format field value for verification display
     *
     * @param string $value
     * @param string $field_id
     * @return string
     */
    public static function formatVerificationFieldValue($value, $field_id)
    {
        $field_def = self::getField($field_id);
        if (!$field_def) {
            return $value;
        }

        $save_format = $field_def['save_format'] ?? null;
        if ($save_format === 'yes_no' || $save_format === 'checkbox') {
            return self::isTruthyValue($value) ? 'Áno' : 'Nie';
        }

        $type = $field_def['type'] ?? '';
        if (in_array($type, ['select', 'multiselect'], true) && is_array($field_def['options'] ?? null)) {
            if (is_array($value)) {
                $mapped = array_map(function ($v) use ($field_def) {
                    $key = (string) $v;
                    return $field_def['options'][$key] ?? $v;
                }, $value);
                return implode(', ', array_filter(array_map('strval', $mapped)));
            }
            if (isset($field_def['options'][(string) $value])) {
                return (string) $field_def['options'][(string) $value];
            }
        }

        if (in_array($type, ['text', 'textarea'])) {
            return esc_html($value);
        }

        return $value;
    }

    /**
     * Format participant column content
     *
     * @param string $content
     * @param string $column
     * @param int $post_id
     * @return string
     */
    public static function formatParticipantColumnContent($content, $column, $post_id)
    {
        $field_def = self::getField($column);
        if (!$field_def) {
            return $content;
        }

        $state = ParticipantState::get($post_id);
        $value = $state ? $state->getMeta($column) : '';
        $save_format = $field_def['save_format'] ?? null;

        if ($save_format === 'yes_no' || $save_format === 'checkbox') {
            return self::isTruthyValue($value) ? 'Áno' : 'Nie';
        }

        $type = $field_def['type'] ?? '';
        if (in_array($type, ['select', 'multiselect'], true) && is_array($field_def['options'] ?? null)) {
            if (is_array($value)) {
                $mapped = array_map(function ($v) use ($field_def) {
                    $key = (string) $v;
                    return $field_def['options'][$key] ?? $v;
                }, $value);
                return implode(', ', array_filter(array_map('strval', $mapped)));
            }
            if ($value !== '' && isset($field_def['options'][(string) $value])) {
                return (string) $field_def['options'][(string) $value];
            }
        }

        return $value ?: $content;
    }

    /**
     * Get meta fields configuration for admin
     *
     * @return array
     */
    public static function getMetaFields()
    {
        $field_definitions = self::getFields();

        uasort($field_definitions, function ($a, $b) {
            return ($a['priority'] ?? 999) <=> ($b['priority'] ?? 999);
        });

        $meta_fields = [];
        foreach ($field_definitions as $field_id => $field_def) {
            if (!empty($field_def['ticket_only'])) {
                continue;
            }

            $meta_fields[$field_id] = [
                'label' => $field_def['admin_label'] ?? self::getFieldLabel($field_def),
                'type' => $field_def['type'] === 'checkbox' ? 'checkbox' : $field_def['type'],
            ];

            if (isset($field_def['options'])) {
                $meta_fields[$field_id]['options'] = $field_def['options'];
            }
        }

        return $meta_fields;
    }

    /**
     * Get meta box section fields
     *
     * @return array
     */
    /**
     * Fields the participant admin renders somewhere else.
     *
     * They either map to native WooCommerce billing (shown in the Address and
     * Company sections) or are managed by the SuperFaktura plugin. Listing them
     * again would duplicate the same input, which is exactly what happened while
     * the two admin paths kept their own copy of this list.
     *
     * @return string[]
     */
    private static function adminManagedElsewhere(): array
    {
        return [
            'company_name', 'street', 'city', 'zip', 'country',
            'wi_as_company', 'company_wi_id', 'company_wi_tax', 'company_wi_vat',
        ];
    }

    public static function getMetaBoxSectionFields()
    {
        $field_definitions = self::getFields();
        $skip = self::adminManagedElsewhere();
        $fields = ['participant_type'];

        foreach ($field_definitions as $field_id => $field_def) {
            if (!empty($field_def['ticket_only'])) {
                continue;
            }
            if (in_array($field_id, $skip, true)) {
                continue;
            }
            $fields[] = $field_id;
        }

        return $fields;
    }

    /**
     * Get export field order
     *
     * @return array
     */
    public static function getExportFieldOrder()
    {
        $field_definitions = self::getFields();
        $order = ['variable_symbol', 'participant_type'];

        uasort($field_definitions, function ($a, $b) {
            return ($a['priority'] ?? 999) <=> ($b['priority'] ?? 999);
        });

        foreach ($field_definitions as $field_id => $field_def) {
            if (!in_array($field_id, $order)) {
                $order[] = $field_id;
            }
        }

        return $order;
    }

    /**
     * Get ticket data configuration
     *
     * @param array $data Participant meta data
     * @return array ['first_column' => [...], 'second_column' => [...]]
     */
    public static function getTicketData($data)
    {
        $field_definitions = self::getFields();
        $ticket_data = ['first_column' => [], 'second_column' => []];

        $ticket_fields = [];
        foreach ($field_definitions as $field_id => $field_def) {
            // Check for ticket config (new format)
            $has_ticket = false;
            $ticket_column = 'first';

            if (isset($field_def['ticket']) && is_array($field_def['ticket'])) {
                $has_ticket = !empty($field_def['ticket']['enabled']);
                $ticket_column = $field_def['ticket']['column'] ?? 'first';
            } elseif (isset($field_def['ticket_label']) && isset($field_def['ticket_column'])) {
                // Legacy format
                $has_ticket = true;
                $ticket_column = $field_def['ticket_column'];
            }

            if (!$has_ticket) {
                continue;
            }

            if (!isset($data[$field_id]) || $data[$field_id] === '' || $data[$field_id] === null) {
                continue;
            }

            if (isset($field_def['ticket_condition'])) {
                $condition_field = $field_def['ticket_condition'];
                if (empty($data[$condition_field]) || $data[$condition_field] !== '1') {
                    continue;
                }
            }

            $field_def['_ticket_column'] = $ticket_column;
            $ticket_fields[$field_id] = $field_def;
        }

        uasort($ticket_fields, function ($a, $b) {
            return ($a['priority'] ?? 999) <=> ($b['priority'] ?? 999);
        });

        foreach ($ticket_fields as $field_id => $field_def) {
            $value = $data[$field_id];
            $save_format = $field_def['save_format'] ?? null;

            if ($save_format === 'yes_no' || $save_format === 'checkbox') {
                $value = self::isTruthyValue($value) ? 'Áno' : 'Nie';
            }

            $column = $field_def['_ticket_column'] . '_column';
            $ticket_data[$column][] = [
                'key' => $field_id,
                'label' => self::getTicketLabel($field_def),
                'value' => $value,
            ];
        }

        return $ticket_data;
    }

    /**
     * Generate CSS for field styling (conditional fields)
     *
     * @return string
     */
    public static function generateCss()
    {
        $field_definitions = self::getFields();
        $css = '';
        $transition_classes = [];
        $hidden_classes = [];

        foreach ($field_definitions as $field_def) {
            if (!isset($field_def['class']) || !is_array($field_def['class'])) {
                continue;
            }
            foreach ($field_def['class'] as $class) {
                if (strpos($class, '-field') !== false && strpos($class, '--hidden') === false) {
                    $hidden_class = $class . '--hidden';
                    if (in_array($hidden_class, $field_def['class'])) {
                        $transition_classes[] = $class;
                        $hidden_classes[] = $hidden_class;
                    }
                }
            }
        }

        foreach (array_unique($transition_classes) as $class) {
            $css .= ".{$class} { transition: opacity 0.3s ease 0.3s, ";
            $css .= "visibility 0.3s ease 0.3s, height 0.3s ease 0.3s; }\n";
        }

        foreach (array_unique($hidden_classes) as $class) {
            $css .= ".{$class} { visibility: hidden; height: 0; ";
            $css .= "overflow: hidden; opacity: 0; }\n";
        }

        return rtrim($css);
    }

    /**
     * Get JavaScript configuration for field interactions
     *
     * @return array
     */
    public static function getJavaScriptConfig()
    {
        $field_definitions = self::getFields();
        $toggle_configs = [];
        $ajax_configs = [];

        foreach ($field_definitions as $field_id => $field_def) {
            if (!isset($field_def['javascript_config'])) {
                continue;
            }

            $js_config = $field_def['javascript_config'];
            $base = array_merge($js_config, [
                'field_key' => $field_def['key'],
            ]);

            switch ($js_config['type']) {
                case 'toggle':
                    $toggle_configs[$field_id] = $base;
                    break;
                case 'ajax_toggle':
                    $ajax_configs[$field_id] = $base;
                    break;
            }
        }

        return [
            'toggle_configs' => $toggle_configs,
            'ajax_configs' => $ajax_configs,
            'ajax_url' => admin_url('admin-ajax.php'),
        ];
    }

    /**
     * Get field groups
     *
     * @return array
     */
    public static function getFieldGroups()
    {
        $field_definitions = self::getFields();
        $groups = [];

        foreach ($field_definitions as $field_id => $field_def) {
            $group = $field_def['group'] ?? 'common';
            $groups[$group][$field_id] = $field_def;
        }

        return $groups;
    }

    // =========================================================================
    // Import / Export
    // =========================================================================

    /**
     * Export fields as JSON string
     *
     * @return string
     */
    public static function exportToJson()
    {
        return wp_json_encode(self::getFields(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Import fields from JSON string
     *
     * @param string $json
     * @param bool $merge If true, merge with existing; if false, replace
     * @return bool|string True on success, error message on failure
     */
    public static function importFromJson($json, $merge = false)
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return __('Invalid JSON format', 'alttag-registrations');
        }

        if (!is_array($data) || empty($data)) {
            return __('No field definitions found in JSON', 'alttag-registrations');
        }

        // Validate structure
        foreach ($data as $key => $field) {
            if (!isset($field['key'])) {
                return sprintf(
                    __('Field "%s" is missing required "key" property', 'alttag-registrations'),
                    $key
                );
            }
        }

        if ($merge) {
            $existing = get_option(self::OPTION_KEY, []);
            $data = array_merge($existing, $data);
        }

        self::saveFields($data);
        return true;
    }

    // =========================================================================
    // Default system fields
    // =========================================================================

    /**
     * Get the 10 default system fields
     *
     * @return array
     */
    public static function getDefaultFields()
    {
        $languages = self::getAvailableLanguages();
        $lang_keys = array_keys($languages);
        $default_lang = $lang_keys[0] ?? 'sk';

        $defaults = [
            'first_name' => [
                'labels' => ['sk' => 'Meno', 'en' => 'First Name'],
                'ticket_labels' => ['sk' => 'Meno účastníka', 'en' => "Participant's First Name"],
                'priority' => 1,
                'ticket_column' => 'first',
            ],
            'last_name' => [
                'labels' => ['sk' => 'Priezvisko', 'en' => 'Last Name'],
                'ticket_labels' => ['sk' => 'Priezvisko účastníka', 'en' => "Participant's Last Name"],
                'priority' => 2,
                'ticket_column' => 'first',
            ],
            'email' => [
                'labels' => ['sk' => 'Email', 'en' => 'Email'],
                'ticket_labels' => ['sk' => 'Email', 'en' => 'Email'],
                'priority' => 3,
                'ticket_column' => 'first',
            ],
            'phone' => [
                'labels' => ['sk' => 'Telefón', 'en' => 'Phone'],
                'ticket_labels' => ['sk' => 'Telefón', 'en' => 'Phone'],
                'priority' => 4,
                'ticket_column' => 'first',
            ],
            'street' => [
                'labels' => ['sk' => 'Ulica', 'en' => 'Street'],
                'ticket_labels' => ['sk' => 'Ulica', 'en' => 'Street'],
                'priority' => 5,
                'ticket_column' => 'first',
            ],
            'zip' => [
                'labels' => ['sk' => 'PSČ', 'en' => 'ZIP Code'],
                'ticket_labels' => ['sk' => 'PSČ', 'en' => 'ZIP Code'],
                'priority' => 6,
                'ticket_column' => 'first',
            ],
            'city' => [
                'labels' => ['sk' => 'Mesto', 'en' => 'City'],
                'ticket_labels' => ['sk' => 'Mesto', 'en' => 'City'],
                'priority' => 7,
                'ticket_column' => 'first',
            ],
            'country' => [
                'labels' => ['sk' => 'Krajina', 'en' => 'Country'],
                'ticket_labels' => ['sk' => 'Krajina', 'en' => 'Country'],
                'priority' => 8,
                'ticket_column' => 'first',
            ],
            'company_name' => [
                'labels' => ['sk' => 'Spoločnosť', 'en' => 'Company'],
                'ticket_labels' => ['sk' => 'Spoločnosť', 'en' => 'Company'],
                'priority' => 1,
                'ticket_column' => 'second',
            ],
            'variable_symbol' => [
                'labels' => ['sk' => 'Variabilný symbol', 'en' => 'Variable Symbol'],
                'ticket_labels' => ['sk' => 'Variabilný symbol', 'en' => 'Variable Symbol'],
                'priority' => 2,
                'ticket_column' => 'second',
            ],
        ];

        $fields = [];
        foreach ($defaults as $key => $def) {
            $fields[$key] = [
                'key' => $key,
                'labels' => $def['labels'],
                'type' => 'text',
                'required' => false,
                'class' => ['form-row-wide'],
                'priority' => $def['priority'],
                'admin_class' => 'short',
                'admin_type' => 'text',
                'group' => 'common',
                'display_conditions' => [],
                'ticket' => [
                    'enabled' => true,
                    'labels' => $def['ticket_labels'],
                    'column' => $def['ticket_column'],
                ],
                'ticket_only' => true,
                'is_system' => true,
                'contexts' => ['checkout', 'admin', 'email', 'verification', 'export'],
            ];
        }

        return $fields;
    }

    // =========================================================================
    // SuperFaktúra integration
    // =========================================================================

    /**
     * Check if SuperFaktúra plugin is active
     */
    public static function isSuperfakturaActive()
    {
        return RegistrationContext::current()->hasInvoicing();
    }

    /**
     * Get SuperFaktúra system field definitions (IČO, DIČ, IČ DPH)
     *
     * @return array
     */
    public static function getSuperfakturaSystemFields()
    {
        $defaults = [
            'wi_as_company' => [
                'labels' => ['sk' => 'Fakturovať na firmu', 'en' => 'Buy as Business client'],
                'priority' => 24,
            ],
            'company_wi_id' => [
                'labels' => ['sk' => 'IČO', 'en' => 'Company ID'],
                'priority' => 25,
            ],
            'company_wi_tax' => [
                'labels' => ['sk' => 'DIČ', 'en' => 'Tax ID'],
                'priority' => 26,
            ],
            'company_wi_vat' => [
                'labels' => ['sk' => 'IČ DPH', 'en' => 'VAT ID'],
                'priority' => 27,
            ],
        ];

        $fields = [];
        foreach ($defaults as $key => $def) {
            $fields[$key] = [
                'key' => 'billing_' . $key,
                'labels' => $def['labels'],
                'type' => 'text',
                'required' => false,
                'class' => ['form-row-wide'],
                'priority' => $def['priority'],
                'admin_class' => 'short',
                'admin_type' => 'text',
                'group' => 'common',
                'display_conditions' => [],
                'ticket' => [
                    'enabled' => false,
                    'labels' => $def['labels'],
                    'column' => 'second',
                ],
                'ticket_only' => false,
                'is_system' => true,
                'contexts' => ['admin', 'email', 'export'],
            ];
        }

        // The business toggle is a checkbox, not a text input: the SF plugin
        // renders it as type 'checkbox' at checkout and CheckoutManager stores
        // it as '1' / '0'. Without these overrides the Excel export printed the
        // raw stored value instead of Áno / Nie, the convention every other
        // boolean column (charity_run, …) already uses.
        $fields['wi_as_company']['type'] = 'checkbox';
        $fields['wi_as_company']['admin_type'] = 'checkbox';
        $fields['wi_as_company']['save_format'] = 'checkbox';
        $fields['wi_as_company']['export'] = ['enabled' => true, 'format' => 'boolean'];

        return $fields;
    }
}
