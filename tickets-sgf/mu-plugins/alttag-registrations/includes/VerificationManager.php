<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

class VerificationManager
{
    private $participantManager;
    private $ticketManager;
    private $qrCodeManager;

    public function registerHooks()
    {
        // Add filter to enable/disable verification
        $enabled = apply_filters('alttag_registrations_enable_verification', true);

        if (!$enabled) {
            return;
        }

        add_action('init', [$this, 'addRewriteRules']);
        add_filter('alttag_registrations_verification_sections', [$this, 'extendVerificationSections']);
        add_filter('alttag_registrations_meta_fields', [$this, 'extendMetaFields']);
        add_filter('alttag_registrations_verification_field_value', [$this, 'filterVerificationFieldValue'], 10, 3);
        add_filter('alttag_registrations_customer_data', [$this, 'extendCustomerData'], 10, 3);
        add_action('alttag_registrations_verification_participant_details_fields', [$this, 'renderAlwaysShowVerificationFields']);
        add_filter('alttag_registrations_verification_field_label', [$this, 'filterVerificationFieldLabel'], 10, 3);
        add_filter('alttag_registrations_show_register_button', [$this, 'filterShowRegisterButton'], 10, 2);
        add_action('alttag_registrations_handle_custom_action', [$this, 'handleCustomVerificationAction'], 10, 2);
    }

    public function extendVerificationSections($sections)
    {
        if (isset($sections['personal']) && is_array($sections['personal']['fields'])) {
            $verification_fields = FieldBuilder::getVerificationFields();
            $field_definitions = FieldBuilder::getFields();
            $remaining_custom_fields = [];

            foreach ($verification_fields as $field_id) {
                $always_show = $field_definitions[$field_id]['always_show_verification'] ?? false;
                if ($always_show) {
                    continue;
                }

                if (isset($field_definitions[$field_id])) {
                    $remaining_custom_fields[] = $field_id;
                }
            }

            $new_personal_fields = [];
            if (in_array('participant_type', $verification_fields, true)) {
                $new_personal_fields[] = 'participant_type';
            }

            $new_personal_fields = array_merge($new_personal_fields, $sections['personal']['fields']);
            $sections['personal']['fields'] = array_merge($new_personal_fields, $remaining_custom_fields);
        }

        if (isset($sections['registration']) && is_array($sections['registration']['fields'])) {
            foreach (['product_name', 'selected_days_display'] as $field_id) {
                if (!in_array($field_id, $sections['registration']['fields'], true)) {
                    $sections['registration']['fields'][] = $field_id;
                }
            }
        }

        return $sections;
    }

    public function extendMetaFields($fields)
    {
        foreach (FieldBuilder::getMetaFields() as $key => $field) {
            if (isset($fields[$key])) {
                // Preserve system field config such as select options while still
                // allowing FieldBuilder labels/types to extend the definition.
                $fields[$key] = array_merge($fields[$key], $field);
                continue;
            }

            $fields[$key] = $field;
        }

        return $fields;
    }

    public function filterVerificationFieldValue($value, $field_id, $customerData)
    {
        return FieldBuilder::formatVerificationFieldValue($value, $field_id);
    }

    public function extendCustomerData($data, $participantId, $participant)
    {
        $state = ParticipantState::get($participant->ID);
        $field_definitions = FieldBuilder::getFields();

        foreach ($field_definitions as $field_id => $field_def) {
            $data[$field_id] = $state ? $state->getMeta($field_id) : '';
        }

        $data['participant_type'] = $state ? $state->participant_type : '';

        foreach ($field_definitions as $field_id => $field_def) {
            if (!empty($field_def['always_show_verification']) && (!isset($data[$field_id]) || $data[$field_id] === '')) {
                $data[$field_id] = '0';
            }
        }

        return $data;
    }

    public function renderAlwaysShowVerificationFields($customerData)
    {
        $field_definitions = FieldBuilder::getFields();
        $always_show_fields = [];

        foreach ($field_definitions as $field_id => $field_def) {
            if (!empty($field_def['always_show_verification'])) {
                $always_show_fields[$field_id] = $field_def;
            }
        }

        uasort($always_show_fields, function ($a, $b) {
            return ($a['priority'] ?? 999) <=> ($b['priority'] ?? 999);
        });

        foreach ($always_show_fields as $field_id => $field_def) {
            $value = $customerData[$field_id] ?? '0';
            $formatted_value = FieldBuilder::formatVerificationFieldValue($value, $field_id);

            printf(
                '<li><strong>%s:</strong> %s</li>',
                wp_kses_post($field_def['label']),
                esc_html($formatted_value)
            );
        }
    }

    public function filterVerificationFieldLabel($label, $field_id, $customerData)
    {
        $field_def = FieldBuilder::getField($field_id);
        if ($field_def) {
            return $field_def['admin_label'] ?? FieldBuilder::getFieldLabel($field_def);
        }

        $fallback_labels = [
            'participant_type' => __('Participant type', 'alttag-registrations'),
            'product_name' => __('Product', 'alttag-registrations'),
            'selected_days_display' => __('Selected days', 'alttag-registrations'),
            'create_date' => __('Registration date', 'alttag-registrations'),
            'update_date' => __('Updated at', 'alttag-registrations'),
        ];

        return $fallback_labels[$field_id] ?? $label;
    }

    public function filterShowRegisterButton($show, $customerData)
    {
        $participant_id = $customerData['id'] ?? null;
        if (!$participant_id) {
            return $show;
        }

        $state = ParticipantState::get($participant_id);
        if ($state && !empty($state->selectedDays())) {
            return false;
        }

        return $show;
    }

    public function handleCustomVerificationAction($variable_symbol, $action)
    {
        if (strpos($action, 'checkin_person_') !== 0) {
            return;
        }

        $date = str_replace('checkin_person_', '', $action);
        $participant = get_participant_by_variable_symbol($variable_symbol);
        if (!$participant) {
            return;
        }

        $state = ParticipantState::get($participant->ID);
        if (!$state) {
            return;
        }

        $days_data = $state->selectedDaysData();
        $verified_data = $state->verifiedDaysData();
        $max_persons = isset($days_data[$date]) ? (int) $days_data[$date] : 1;
        $current = isset($verified_data[$date]) ? (int) $verified_data[$date] : 0;

        if ($current >= $max_persons) {
            return;
        }

        $verified_data[$date] = $current + 1;
        $state->setMeta('verified_days_data', $verified_data);

        $date_labels = get_participant_available_date_labels($state->id);
        $date_label = $date_labels[$date] ?? $date;
        $new_count = $current + 1;

        $state->addToHistory(sprintf(
            '%s: %s %d/%d (%s)',
            $date_label,
            __('person checked in', 'alttag-registrations'),
            $new_count,
            $max_persons,
            wp_get_current_user()->user_email
        ));

        $registered_dates = $state->registeredDates();
        if (!in_array($date, $registered_dates, true)) {
            $registered_dates[] = $date;
            $state->setMeta('registered_dates', $registered_dates);

            $selected_days = $state->selectedDays();
            $state->setMeta(
                'registration_status',
                count($registered_dates) >= count($selected_days) ? 'completed' : 'partial'
            );
        }
    }

    /**
     * Check out a single person for a specific date (-1).
     */
    public function checkoutPersonForDate($variable_symbol, $date)
    {
        $participant = $this->participantManager->getParticipantByVariableSymbol($variable_symbol);
        if (!$participant) {
            return false;
        }

        $state = ParticipantState::get($participant->ID);
        if (!$state) {
            return false;
        }

        $verified_data = $state->verifiedDaysData();
        $current = isset($verified_data[$date]) ? (int) $verified_data[$date] : 0;

        if ($current <= 0) {
            return false;
        }

        $verified_data[$date] = $current - 1;
        $state->setMeta('verified_days_data', $verified_data);

        $days_data = $state->selectedDaysData();
        $max_persons = isset($days_data[$date]) ? (int) $days_data[$date] : 1;
        $date_labels = get_participant_available_date_labels($participant->ID);

        $state->addToHistory(sprintf(
            '%s: %s %d/%d (%s)',
            $date_labels[$date] ?? $date,
            __('person checked out', 'alttag-registrations'),
            $current - 1,
            $max_persons,
            wp_get_current_user()->user_email
        ));

        // If no more verified persons, remove from registered dates
        if ($current - 1 <= 0) {
            $registered_dates = $state->registeredDates();
            $key = array_search($date, $registered_dates, true);
            if ($key !== false) {
                unset($registered_dates[$key]);
                $registered_dates = array_values($registered_dates);
                $state->setMeta('registered_dates', $registered_dates);
                $state->setMeta('registration_status', empty($registered_dates) ? 'cancelled' : 'partial');
            }
        }

        return true;
    }

    /**
     * Set the participant manager
     *
     * @param ParticipantManager $participantManager The participant manager
     */
    public function setParticipantManager($participantManager)
    {
        $this->participantManager = $participantManager;
    }

    /**
     * Set the ticket manager
     *
     * @param TicketManager $ticketManager The ticket manager
     */
    public function setTicketManager($ticketManager)
    {
        $this->ticketManager = $ticketManager;
    }

    /**
     * Set the QR code manager
     *
     * @param QRCodeManager $qrCodeManager The QR code manager
     */
    public function setQRCodeManager($qrCodeManager)
    {
        $this->qrCodeManager = $qrCodeManager;
    }

    /**
     * Generate QR code URL for verification
     * Delegates to QRCodeManager
     *
     * @param string $variableSymbol The variable symbol
     * @return string The QR code URL
     */
    public function generateQRCodeUrl($variableSymbol)
    {
        if (!$this->qrCodeManager) {
            return '';
        }
        return $this->qrCodeManager->generateQRCodeUrl($variableSymbol);
    }

    /**
     * Add rewrite rules
     */
    public function addRewriteRules()
    {
        // Add QR code rewrite rule
        add_rewrite_rule(
            '^' . ALTTAG_REGISTRATIONS_QR_CODE_BASE_URL . '/([^/]+)/?$',
            'index.php?qr_code_symbol=$matches[1]',
            'top'
        );

        // Add download ticket rewrite rule
        add_rewrite_rule(
            '^' . ALTTAG_REGISTRATIONS_TICKET_DOWNLOAD_FOLDER . '/([^/]+)/?$',
            'index.php?download_ticket=$matches[1]',
            'top'
        );

        // Add query vars
        add_filter('query_vars', function ($vars) {
            $vars[] = 'qr_code_symbol';
            $vars[] = 'download_ticket';
            return $vars;
        });
    }


    /**
     * Get the verification URL
     *
     * @param string $variableSymbol The variable symbol
     *
     * @return string The verification URL
     */
    public function getVerificationUrl($variableSymbol)
    {
        return home_url("/" . ALTTAG_REGISTRATIONS_VERIFY_BASE_URL . "/{$variableSymbol}");
    }

    /**
     * Get the ticket URL (delegates to TicketManager)
     *
     * @param string $variableSymbol The variable symbol
     * @return string The ticket URL
     */
    public function getTicketUrl($variableSymbol)
    {
        return $this->ticketManager->getTicketUrl($variableSymbol);
    }


    public function verifyAndGetCustomerData($variableSymbol)
    {
        $args = array(
            'post_type' => 'participant',
            'meta_query' => array(
                array(
                    'key' => 'variable_symbol',
                    'value' => $variableSymbol,
                    'compare' => '='
                )
            ),
            'posts_per_page' => 1
        );

        $query = new \WP_Query($args);

        if (!$query->have_posts()) {
            return false;
        }

        $participant = $query->posts[0];
        return $this->formatCustomerData($participant->ID);
    }

    public function formatCustomerData($participantId)
    {
        $participant = get_post($participantId);
        if (!$participant) {
            return false;
        }

        $state = \Alttag\Registrations\ParticipantState::get($participantId);
        if (!$state) {
            return false;
        }

        // Build data from ParticipantState (single DB query, cached)
        $data = $state->toArray();

        // Ensure registered_dates is always an array
        if (empty($data['registered_dates']) || !is_array($data['registered_dates'])) {
            $data['registered_dates'] = [];
        }

        $data['product_name'] = $state->productName();

        $days_data = $state->selectedDaysData();
        if (!empty($days_data)) {
            $data['selected_days_data'] = $days_data;
            $data['selected_days_display'] = \Alttag\Registrations\get_selected_days_data_label($days_data);
            $data['total_persons'] = \Alttag\Registrations\get_total_persons_from_days_data($days_data);
        } else {
            $selected_days = $state->selectedDays();
            if (!empty($selected_days)) {
                $data['selected_days_display'] = \Alttag\Registrations\get_selected_days_label($selected_days);
            }
        }

        /**
         * Filter to add event-specific fields to customer data
         *
         * @param array $data The customer data array
         * @param int $participantId The participant ID
         * @param WP_Post $participant The participant post object
         * @return array Modified customer data
         */
        return apply_filters('alttag_registrations_customer_data', $data, $participantId, $participant);
    }

    public function handleVerification($variable_symbol)
    {
        if (!is_user_logged_in()) {
            auth_redirect();
        }

        $customerData = $this->verifyAndGetCustomerData($variable_symbol);

        if (!$customerData) {
            wp_die(__("Invalid verification code.", "alttag-registrations"), __("Verification error", "alttag-registrations"));
            return;
        }

        $default_template = ALTTAG_REGISTRATIONS_PATH . '/templates/verification-page.php';

        /**
         * Filter the template used to render the verification page.
         *
         * @param string $template_path Absolute path to the verification page template.
         */
        $template_path = apply_filters('alttag_registrations_verification_template_path', $default_template);

        if (!is_string($template_path) || !file_exists($template_path)) {
            $template_path = $default_template;
        }

        ob_start();
        include($template_path);
        $content = ob_get_clean();

        echo $this->addWordPressHeaderAndFooter($content);
    }

    /**
     * Update the registration status for a participant
     *
     * @param WP_Post $participant The participant post object
     * @param string $status The new status
     * @param string $source The source of the update (default: 'manual')
     *
     * @return bool True if the registration status was updated successfully, false otherwise
     */
    private function updateRegistrationStatus($participant, $status, $source = 'manual')
    {
        $state = \Alttag\Registrations\ParticipantState::get($participant->ID);
        if (!$state) {
            return false;
        }

        $state->setMeta('registration_status', $status);

        $status_label = $this->getStatusLabel($status);
        $source_label = $this->getSourceLabel($source);
        $current_user = wp_get_current_user();

        $state->addToHistory(sprintf(
            __('Registration %s by %s via %s', 'alttag-registrations'),
            $status_label,
            $current_user->user_email,
            $source_label
        ));

        return true;
    }

    /**
     * Mark a participant as registered
     *
     * @param string $variable_symbol The variable symbol of the participant
     * @param string $source The source of the registration (default: 'manual')
     *
     * @return bool True if the participant was marked as registered, false otherwise
     */
    public function markAsRegistered($variable_symbol, $source = 'manual')
    {
        $participant = $this->participantManager->getParticipantByVariableSymbol($variable_symbol);
        if (!$participant) {
            return false;
        }
        return $this->updateRegistrationStatus($participant, 'confirmed', $source);
    }

    /**
     * Cancel a participant's registration
     *
     * @param string $variable_symbol The variable symbol of the participant
     *
     * @return bool True if the registration was cancelled, false otherwise
     */
    public function cancelRegistration($variable_symbol)
    {
        $participant = $this->participantManager->getParticipantByVariableSymbol($variable_symbol);
        if (!$participant) {
            return false;
        }

        // Clear per-day registrations if participant has selected days
        $state = ParticipantState::get($participant->ID);
        if ($state && !empty($state->selectedDays())) {
            $state->setMeta('registered_dates', []);
        }

        return $this->updateRegistrationStatus($participant, 'cancelled');
    }

    /**
     * Mark a participant as registered for a specific date
     *
     * @param string $variable_symbol The variable symbol of the participant
     * @param string $date The date to register for
     * @param string $source The source of the registration (default: 'manual')
     *
     * @return bool True if the participant was marked as registered for the date, false otherwise
     */
    public function markAsRegisteredForDate($variable_symbol, $date, $source = 'manual')
    {
        $participant = $this->participantManager->getParticipantByVariableSymbol($variable_symbol);
        if (!$participant) {
            return false;
        }

        $state = \Alttag\Registrations\ParticipantState::get($participant->ID);
        if (!$state) {
            return false;
        }

        // Validate date against participant's selected days
        $selected_days = $state->selectedDays();
        if (!in_array($date, $selected_days, true)) {
            return false;
        }

        $registered_dates = $state->registeredDates();

        if (!in_array($date, $registered_dates)) {
            $registered_dates[] = $date;
            $state->setMeta('registered_dates', $registered_dates);
        }

        // Mirror the registration into verified_days_data so the "Persons per
        // day" admin metabox + per-day check-in tables reflect the check-in.
        // For a 1-person-per-day registration (summer flow, single-person
        // events) this sets count to 1; for multi-person dates it bumps to
        // the full expected count, treating the "Register" action as
        // "register all expected persons for this day".
        $verified_data = $state->verifiedDaysData();
        $verified_data[$date] = $state->personCountForDate($date);
        $state->setMeta('verified_days_data', $verified_data);

        if (count($registered_dates) === 1) {
            $this->updateRegistrationStatus($participant, 'confirmed', $source);
        }

        $available_dates = get_registrations_available_dates();
        $current_user = wp_get_current_user();
        $date_label = $available_dates[$date] ?? $date;
        $state->addToHistory(sprintf(
            __('Registered for %s by %s', 'alttag-registrations'),
            $date_label,
            $current_user->user_email
        ));

        return true;
    }

    /**
     * Mark a participant as registered for all selected dates at once
     *
     * @param string $variable_symbol The variable symbol of the participant
     * @param string $source The source of the registration (default: 'manual')
     * @return bool
     */
    public function markAllAsRegistered($variable_symbol, $source = 'manual')
    {
        $participant = $this->participantManager->getParticipantByVariableSymbol($variable_symbol);
        if (!$participant) {
            return false;
        }

        $state = ParticipantState::get($participant->ID);
        if (!$state) {
            return false;
        }

        $selected_days = $state->selectedDays();
        if (empty($selected_days)) {
            return false;
        }

        $date_labels = get_participant_available_date_labels($participant->ID);
        $days_data = $state->selectedDaysData();
        $verified_data = $state->verifiedDaysData();
        $registered_dates = $state->registeredDates();
        $current_user = wp_get_current_user();
        $newly_registered = [];

        foreach ($selected_days as $date) {
            if (!in_array($date, $registered_dates, true)) {
                $registered_dates[] = $date;
                $newly_registered[] = $date_labels[$date] ?? $date;
            }
            // Check in all persons for this date
            $max_persons = isset($days_data[$date]) ? (int) $days_data[$date] : 1;
            $verified_data[$date] = $max_persons;
        }

        if (empty($newly_registered)) {
            return false;
        }

        $state->setMeta('registered_dates', $registered_dates);
        $state->setMeta('verified_days_data', $verified_data);
        $this->updateRegistrationStatus($participant, 'confirmed', $source);

        $state->addToHistory(sprintf(
            __('Bulk registered for all days (%s) by %s', 'alttag-registrations'),
            implode(', ', $newly_registered),
            $current_user->user_email
        ));

        return true;
    }

    /**
     * Check in all persons for a specific date.
     */
    public function checkinAllPersonsForDate($variable_symbol, $date)
    {
        $participant = $this->participantManager->getParticipantByVariableSymbol($variable_symbol);
        if (!$participant) {
            return false;
        }

        $state = ParticipantState::get($participant->ID);
        if (!$state) {
            return false;
        }

        $days_data = $state->selectedDaysData();
        $max_persons = isset($days_data[$date]) ? (int) $days_data[$date] : 1;
        $verified_data = $state->verifiedDaysData();
        $current = isset($verified_data[$date]) ? (int) $verified_data[$date] : 0;

        if ($current >= $max_persons) {
            return false;
        }

        $verified_data[$date] = $max_persons;
        $state->setMeta('verified_days_data', $verified_data);

        $registered_dates = $state->registeredDates();
        if (!in_array($date, $registered_dates, true)) {
            $registered_dates[] = $date;
            $state->setMeta('registered_dates', $registered_dates);
        }

        $selected_days = $state->selectedDays();
        $state->setMeta(
            'registration_status',
            count($registered_dates) >= count($selected_days) ? 'completed' : 'partial'
        );

        $date_labels = get_participant_available_date_labels($participant->ID);
        $state->addToHistory(sprintf(
            '%s: %s %d/%d (%s)',
            $date_labels[$date] ?? $date,
            __('all persons checked in', 'alttag-registrations'),
            $max_persons,
            $max_persons,
            wp_get_current_user()->user_email
        ));

        return true;
    }

    /**
     * Check out all persons for a specific date.
     */
    public function checkoutAllPersonsForDate($variable_symbol, $date)
    {
        $participant = $this->participantManager->getParticipantByVariableSymbol($variable_symbol);
        if (!$participant) {
            return false;
        }

        $state = ParticipantState::get($participant->ID);
        if (!$state) {
            return false;
        }

        $verified_data = $state->verifiedDaysData();
        $verified_data[$date] = 0;
        $state->setMeta('verified_days_data', $verified_data);

        // Remove from registered dates
        $registered_dates = $state->registeredDates();
        $key = array_search($date, $registered_dates, true);
        if ($key !== false) {
            unset($registered_dates[$key]);
            $registered_dates = array_values($registered_dates);
            $state->setMeta('registered_dates', $registered_dates);
        }

        $state->setMeta('registration_status', empty($registered_dates) ? 'cancelled' : 'partial');

        $date_labels = get_participant_available_date_labels($participant->ID);
        $state->addToHistory(sprintf(
            '%s: %s (%s)',
            $date_labels[$date] ?? $date,
            __('all persons checked out', 'alttag-registrations'),
            wp_get_current_user()->user_email
        ));

        return true;
    }

    /**
     * Cancel a participant's registration for all selected dates at once
     *
     * @param string $variable_symbol The variable symbol of the participant
     * @return bool
     */
    public function cancelAllRegistrations($variable_symbol)
    {
        $participant = $this->participantManager->getParticipantByVariableSymbol($variable_symbol);
        if (!$participant) {
            return false;
        }

        $state = ParticipantState::get($participant->ID);
        if (!$state) {
            return false;
        }

        $selected_days = $state->selectedDays();
        if (empty($selected_days)) {
            return false;
        }

        $date_labels = get_participant_available_date_labels($participant->ID);
        $registered_dates = $state->registeredDates();
        $cancelled = [];

        foreach ($selected_days as $date) {
            if (in_array($date, $registered_dates, true)) {
                $cancelled[] = $date_labels[$date] ?? $date;
            }
        }

        if (empty($cancelled)) {
            return false;
        }

        $state->setMeta('registered_dates', []);
        $state->setMeta('verified_days_data', []);
        $this->updateRegistrationStatus($participant, 'cancelled');

        $current_user = wp_get_current_user();
        $state->addToHistory(sprintf(
            __('Bulk cancelled all days (%s) by %s', 'alttag-registrations'),
            implode(', ', $cancelled),
            $current_user->user_email
        ));

        return true;
    }

    /**
     * Cancel a participant's registration for a specific date
     *
     * @param string $variable_symbol The variable symbol of the participant
     * @param string $date The date to cancel registration for
     *
     * @return bool True if the registration for the date was cancelled, false otherwise
     */
    public function cancelRegistrationForDate($variable_symbol, $date)
    {
        $participant = $this->participantManager->getParticipantByVariableSymbol($variable_symbol);
        if (!$participant) {
            return false;
        }

        $state = \Alttag\Registrations\ParticipantState::get($participant->ID);
        if (!$state) {
            return false;
        }

        // Validate date against participant's selected days
        $selected_days = $state->selectedDays();
        if (!in_array($date, $selected_days, true)) {
            return false;
        }

        $registered_dates = $state->registeredDates();

        $key = array_search($date, $registered_dates);
        if ($key !== false) {
            unset($registered_dates[$key]);
            $registered_dates = array_values($registered_dates);
            $state->setMeta('registered_dates', $registered_dates);
        }

        // Mirror cancel into verified_days_data so the admin metabox + per-day
        // tables drop the date back to 0/N.
        $verified_data = $state->verifiedDaysData();
        if (isset($verified_data[$date])) {
            unset($verified_data[$date]);
            $state->setMeta('verified_days_data', $verified_data);
        }

        if (empty($registered_dates)) {
            $this->updateRegistrationStatus($participant, 'cancelled');
        }

        $available_dates = get_registrations_available_dates();
        $current_user = wp_get_current_user();
        $date_label = $available_dates[$date] ?? $date;
        $state->addToHistory(sprintf(
            __('Cancelled registration for %s by %s', 'alttag-registrations'),
            $date_label,
            $current_user->user_email
        ));

        return true;
    }

    /**
     * Get registered dates for a participant
     *
     * @param string $variable_symbol The variable symbol of the participant
     *
     * @return array Array of registered dates
     */
    public function getRegisteredDates($variable_symbol)
    {
        $participant = $this->participantManager->getParticipantByVariableSymbol($variable_symbol);
        if (!$participant) {
            return [];
        }

        $state = \Alttag\Registrations\ParticipantState::get($participant->ID);
        return $state ? $state->registeredDates() : [];
    }

    /**
     * Get the registration status for a participant
     *
     * @param string $variable_symbol The variable symbol of the participant
     *
     * @return string|bool The registration status or false if not found
     */
    public function getRegistrationStatus($variable_symbol)
    {
        $participant = $this->participantManager->getParticipantByVariableSymbol($variable_symbol);
        if (!$participant) {
            return false;
        }

        $state = \Alttag\Registrations\ParticipantState::get($participant->ID);
        return $state ? $state->registrationStatus() : false;
    }

    /**
     * Add WordPress header and footer to the content
     *
     * @param string $content The content to add the header and footer to
     *
     * @return string The content with the header and footer added
     */
    private function addWordPressHeaderAndFooter($content)
    {
        ob_start();
        get_header();
        echo $content;
        get_footer();
        return ob_get_clean();
    }

    /**
     * Generate and save a ticket
     *
     * @param int $participantId The ID of the participant
     * @return bool True if the ticket was generated and saved successfully, false otherwise
     */
    public function generateAndSaveTicket($participantId)
    {
        $participant = get_post($participantId);
        if (!$participant) {
            return false;
        }

        $state = \Alttag\Registrations\ParticipantState::get($participantId);
        $default_generate_ticket = $state ? $state->shouldGetTicket() : true;
        $generate_ticket = apply_filters('alttag_registrations_generate_ticket', $default_generate_ticket, $participantId);
        if (!$generate_ticket) {
            return false;
        }

        $variableSymbol = $state ? $state->variable_symbol : '';
        if (empty($variableSymbol)) {
            return false;
        }

        try {
            // Generate QR code image and URL (delegated to QRCodeManager)
            $this->qrCodeManager->generateAndSaveQRCodeImage($participantId, $variableSymbol);

            // Generate PDF ticket and update metadata (delegated to TicketManager)
            return $this->ticketManager->generateTicketWithMeta($participantId, $variableSymbol);
        } catch (\Exception $e) {
            error_log("Error generating ticket for participant {$participantId}: " . $e->getMessage());
            return false;
        }
    }



    /**
     * Get the status label
     *
     * @param string $status The status
     * @return string The status label
     */
    private function getStatusLabel($status)
    {
        $status_labels = [
            'pending' => __('Pending', 'alttag-registrations'),
            'confirmed' => __('Confirmed', 'alttag-registrations'),
            'cancelled' => __('Cancelled', 'alttag-registrations'),
        ];

        return isset($status_labels[$status]) ? $status_labels[$status] : $status;
    }

    /**
     * Get the source label
     *
     * @param string $source The source
     * @return string The source label
     */
    private function getSourceLabel($source)
    {
        $source_labels = [
            'manual' => __('Manual', 'alttag-registrations'),
        ];

        return isset($source_labels[$source]) ? $source_labels[$source] : $source;
    }
}
