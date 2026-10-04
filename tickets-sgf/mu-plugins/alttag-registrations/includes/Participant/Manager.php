<?php

namespace Alttag\Registrations\Participant;

use Alttag\Registrations\LivestreamManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core participant management functionality
 */
class Manager
{
    private const EXTRA_ORDER_META_KEYS = [
        'product_id',
        'product_name',
        'participants_count',
        // Links the participant to the order LINE ITEM it was created from,
        // so a mixed cart produces one participant per item instead of one
        // per order. Empty on participants created before per-item linking.
        'order_item_id',
    ];

    private $verificationManager;

    public function __construct()
    {
        $enable = apply_filters('alttag_registrations_enable_participants', true);

        if (!$enable) {
            return;
        }

        // Minimal constructor with only essential hooks
        add_action('pre_post_update', [$this, 'updateDateTimeFields'], 10, 2);
    }

    /**
     * Set the verification manager
     *
     * @param object $verificationManager The verification manager
     */
    public function setVerificationManager($verificationManager)
    {
        $this->verificationManager = $verificationManager;
    }

    /**
     * Get the meta fields
     *
     * @return array The meta fields
     */
    public function getMetaFields()
    {
        $fields = [
            'order_id' => ['label' => __('Order ID', 'alttag-registrations'), 'type' => 'text'],
            'product_id' => ['label' => __('Product ID', 'alttag-registrations'), 'type' => 'text', 'readonly' => true],
            'product_name' => ['label' => __('Product', 'alttag-registrations'), 'type' => 'text', 'readonly' => true],
            'variable_symbol' => ['label' => __('Variable Symbol', 'alttag-registrations'), 'type' => 'text', 'required' => true],
            'first_name' => ['label' => __('First Name', 'alttag-registrations'), 'type' => 'text', 'required' => true],
            'last_name' => ['label' => __('Last Name', 'alttag-registrations'), 'type' => 'text', 'required' => true],
            'email' => ['label' => __('Email', 'alttag-registrations'), 'type' => 'email', 'required' => true],
            'phone' => ['label' => __('Phone', 'alttag-registrations'), 'type' => 'text'],
            'company_name' => ['label' => __('Company Name', 'alttag-registrations'), 'type' => 'text'],
            'street' => ['label' => __('Street and House Number', 'alttag-registrations'), 'type' => 'text'],
            'city' => ['label' => __('City', 'alttag-registrations'), 'type' => 'text'],
            'zip' => ['label' => __('ZIP', 'alttag-registrations'), 'type' => 'text'],
            'country' => ['label' => __('Country', 'alttag-registrations'), 'type' => 'select'],
            'business_id' => ['label' => __('Business ID', 'alttag-registrations'), 'type' => 'text'],
            'tax_id' => ['label' => __('Tax ID', 'alttag-registrations'), 'type' => 'text'],
            'vat_id' => ['label' => __('VAT ID', 'alttag-registrations'), 'type' => 'text'],
            'price' => ['label' => __('Price', 'alttag-registrations'), 'type' => 'number', 'step' => '0.01', 'readonly' => true],
            'payment_method' => [
                'label' => __('Payment Method', 'alttag-registrations'),
                'type' => 'select',
                'options' => [
                    'bank_transfer' => __('Bank Transfer', 'alttag-registrations'),
                    'card' => __('Credit Card', 'alttag-registrations'),
                ]
            ],
            'registration_status' => [
                'label' => __('Registration Status', 'alttag-registrations'),
                'type' => 'select',
                'default' => 'pending',
                'options' => [
                    'pending' => __('Pending', 'alttag-registrations'),
                    'confirmed' => __('Confirmed', 'alttag-registrations'),
                    'partial' => __('Partial', 'alttag-registrations'),
                    'completed' => __('Completed', 'alttag-registrations'),
                    'cancelled' => __('Cancelled', 'alttag-registrations'),
                ]
            ],
            'invoice_id' => ['label' => __('Invoice ID', 'alttag-registrations'), 'type' => 'text', 'readonly' => true],
            'invoice_url' => ['label' => __('Invoice URL', 'alttag-registrations'), 'type' => 'url', 'readonly' => true],
            'qr_code_url' => ['label' => __('QR Code URL', 'alttag-registrations'), 'type' => 'url', 'readonly' => true],
            'qr_code_img' => ['label' => __('QR Code', 'alttag-registrations'), 'type' => 'qr_code', 'readonly' => true],
            'ticket_url' => ['label' => __('Ticket URL', 'alttag-registrations'), 'type' => 'url', 'readonly' => true],
            'ticket_file_path' => ['label' => __('Ticket File Path', 'alttag-registrations'), 'type' => 'text', 'readonly' => true],
            'used_coupons' => ['label' => __('Used Coupons', 'alttag-registrations'), 'type' => 'text', 'readonly' => true],
            'create_date' => ['label' => __('Create Date', 'alttag-registrations'), 'type' => 'datetime-local', 'readonly' => true],
            'update_date' => ['label' => __('Update Date', 'alttag-registrations'), 'type' => 'datetime-local', 'readonly' => true],
            'imported_at' => ['label' => __('Imported At', 'alttag-registrations'), 'type' => 'datetime-local', 'readonly' => true],
            'registration_history' => ['label' => __('Registration History', 'alttag-registrations'), 'type' => 'textarea', 'readonly' => true],
            'order_comments' => ['label' => __('Order Notes', 'alttag-registrations'), 'type' => 'textarea'],
        ];

        // Add livestream access field if livestream is enabled
        if (\Alttag\Registrations\RegistrationContext::current()->isLivestreamEnabled()) {
            $fields['is_livestream_user'] = [
                'label' => __('Is Livestream User', 'alttag-registrations'),
                'type' => 'checkbox',
                'default' => false,
            ];
            $fields['livestream_access'] = [
                'label' => __('Livestream Access', 'alttag-registrations'),
                'type' => 'select',
                'default' => LivestreamManager::NOT_GRANTED_STATE,
                'options' => [
                    LivestreamManager::NOT_GRANTED_STATE => __('Not Granted', 'alttag-registrations'),
                    LivestreamManager::PENDING_STATE => __('Pending', 'alttag-registrations'),
                    LivestreamManager::GRANTED_STATE => __('Granted', 'alttag-registrations'),
                    LivestreamManager::FAILED_STATE => __('Failed', 'alttag-registrations'),
                ]
            ];
        }

        // Add recording access field if recording module is enabled
        $core = \Alttag\Registrations\Core::getInstance();
        $recording_module = $core && $core->moduleRegistry ? $core->moduleRegistry->get('recording') : null;
        if ($recording_module && $recording_module->isEnabled()) {
            $fields['is_recording_user'] = [
                'label' => __('Is Recording User', 'alttag-registrations'),
                'type' => 'checkbox',
                'default' => false,
            ];
            $fields['recording_access'] = [
                'label' => __('Recording Access', 'alttag-registrations'),
                'type' => 'select',
                'default' => LivestreamManager::NOT_GRANTED_STATE,
                'options' => [
                    LivestreamManager::NOT_GRANTED_STATE => __('Not Granted', 'alttag-registrations'),
                    LivestreamManager::PENDING_STATE => __('Pending', 'alttag-registrations'),
                    LivestreamManager::GRANTED_STATE => __('Granted', 'alttag-registrations'),
                    LivestreamManager::FAILED_STATE => __('Failed', 'alttag-registrations'),
                ]
            ];
        }

        // Add language field if Polylang is active
        $languageField = $this->getLanguageField();
        if ($languageField !== null) {
            // Insert after tax_id
            $new_fields = [];
            foreach ($fields as $key => $field) {
                $new_fields[$key] = $field;
                if ($key === 'tax_id') {
                    $new_fields['language'] = $languageField;
                }
            }
            $fields = $new_fields;
        }

        return apply_filters('alttag_registrations_meta_fields', $fields);
    }

    /**
     * Get language field configuration
     * Returns null if Polylang is not active, otherwise returns field config
     *
     * @return array|null
     */
    private function getLanguageField(): ?array
    {
        if (!\Alttag\Registrations\RegistrationContext::current()->hasPolylang()) {
            return null;
        }

        $options = [];
        $slugs = pll_languages_list(['fields' => 'slug']);

        foreach ($slugs as $slug) {
            $lang = \PLL()->model->get_language($slug);
            if ($lang) {
                $options[$slug] = $lang->name;
            }
        }

        if (empty($options)) {
            return null;
        }

        return [
            'label' => __('Language', 'alttag-registrations'),
            'type' => 'select',
            'default' => \Alttag\Registrations\get_default_language(),
            'options' => $options,
        ];
    }

    /**
     * Get a participant by order ID
     *
     * @param string $order_id The order ID of the participant
     *
     * @return array|false The participant details or false if not found
     */
    public function getParticipantByOrderId($order_id)
    {
        // Return the BUYER's participant only. Multi-participant checkout
        // also stamps extras with the same order_id, so a bare meta_key
        // lookup can grab an extra (registered_by_participant_id != '')
        // depending on insert order — then WooCommerceManager's
        // createOrUpdateParticipant treats the extra as the buyer and
        // overwrites its meta on the next status change, leaving the extra's
        // post_title but the buyer's meta on a single post (and no separate
        // extras record at all).
        $args = [
            'post_type'   => 'participant',
            'meta_key'    => 'order_id',
            'meta_value'  => $order_id,
            'numberposts' => 1,
            'orderby'     => 'ID',
            'order'       => 'ASC',
            'meta_query'  => [
                'relation' => 'AND',
                [
                    'key'     => 'order_id',
                    'value'   => $order_id,
                ],
                [
                    'relation' => 'OR',
                    ['key' => 'registered_by_participant_id', 'compare' => 'NOT EXISTS'],
                    ['key' => 'registered_by_participant_id', 'value' => '', 'compare' => '='],
                ],
            ],
        ];
        $participant = get_posts($args);
        return $participant[0] ?? [];
    }

    /**
     * Get the participant belonging to one specific order LINE ITEM.
     *
     * Unlike getParticipantByOrderId() this can distinguish the two
     * participants of a mixed cart (e.g. livestream item + in-person item).
     * Returns [] for participants created before per-item linking — callers
     * fall back to the order-scoped lookup for the order's first item.
     *
     * @param int $order_id
     * @param int $order_item_id
     * @return \WP_Post|array
     */
    public function getParticipantByOrderItemId($order_id, $order_item_id)
    {
        if (!$order_item_id) {
            return [];
        }

        $participant = get_posts([
            'post_type'   => 'participant',
            'numberposts' => 1,
            'orderby'     => 'ID',
            'order'       => 'ASC',
            'meta_query'  => [
                'relation' => 'AND',
                ['key' => 'order_id',      'value' => $order_id],
                ['key' => 'order_item_id', 'value' => (int) $order_item_id],
            ],
        ]);

        return $participant[0] ?? [];
    }

    /**
     * Get a participant by variable symbol
     *
     * @param string $variable_symbol The variable symbol of the participant
     *
     * @return array|false The participant details or false if not found
     */
    public function getParticipantByVariableSymbol($variable_symbol)
    {
        $args = [
            'post_type' => 'participant',
            'meta_key' => 'variable_symbol',
            'meta_value' => $variable_symbol,
            'numberposts' => 1,
        ];
        $participant = get_posts($args);
        return $participant[0] ?? [];
    }

    /**
     * Get a participant by email
     *
     * @param string $email The email of the participant
     *
     * @return array|false The participant details or false if not found
     */
    public function getParticipantByEmail($email)
    {
        $args = [
            'post_type' => 'participant',
            'meta_key' => 'email',
            'meta_value' => $email,
            'numberposts' => 1,
        ];
        $participant = get_posts($args);
        return $participant[0] ?? [];
    }

    /**
     * Get a participant by email and livestream type.
     *
     * Used during import to correctly match participants when the same email
     * can have both a livestream and in-person registration.
     *
     * @param string $email The email of the participant
     * @param string $is_livestream '1' for livestream, '' for in-person
     *
     * @return object|array The participant post or empty array if not found
     */
    public function getParticipantByEmailAndType($email, $is_livestream)
    {
        $args = [
            'post_type' => 'participant',
            'numberposts' => 1,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'email',
                    'value' => $email,
                ],
                [
                    'key' => 'is_livestream_user',
                    'value' => $is_livestream ? '1' : '',
                ],
            ],
        ];

        $participant = get_posts($args);
        return $participant[0] ?? [];
    }

    /**
     * Update a participant
     *
     * @param int $post_id The ID of the post
     * @param array $order_data The data of the order
     *
     * @return bool True if the participant was updated, false otherwise
     */
    public function updateParticipant($post_id, $order_data)
    {
        $metaFields = $this->getMetaFields();
        foreach ($metaFields as $key => $field) {
            if (isset($order_data[$key])) {
                update_post_meta($post_id, $key, $order_data[$key]);
            }
        }

        $this->saveExtraOrderMeta($post_id, $order_data);

        // Update the update_date
        update_post_meta($post_id, 'update_date', current_time('mysql'));

        do_action('alttag_registrations_participant_update', $post_id, $order_data);

        // Bust ParticipantState cache before regenerating URLs. Without this,
        // downstream ParticipantState::get() calls return the pre-update
        // snapshot — the old variable_symbol gets baked into ticket_url /
        // qr_code_url while the meta already holds the new one, producing a
        // hash lookup mismatch on click (empty ?hash= in the URL).
        \Alttag\Registrations\ParticipantState::clearCache($post_id);

        // Generate and save QR code URL
        $pState = \Alttag\Registrations\ParticipantState::get($post_id);
        $variable_symbol = $pState ? $pState->variable_symbol : '';
        if ($variable_symbol && $this->verificationManager) {
            $qr_code_url = $this->verificationManager->generateQRCodeUrl($variable_symbol);
            update_post_meta($post_id, 'qr_code_url', $qr_code_url);

            // Generate ticket
            $this->verificationManager->generateAndSaveTicket($post_id);
        }

        return true;
    }

    /**
     * Create a new participant
     *
     * @param array $order_data The data of the order
     *
     * @return int|false The ID of the participant or false if failed
     */
    public function createParticipant($order_data)
    {
        // Create post
        $post_data = [
            'post_title'  => $order_data['first_name'] . ' ' . $order_data['last_name'],
            'post_status' => 'publish',
            'post_type'   => 'participant'
        ];

        $participant_post_id = wp_insert_post($post_data);

        if (is_wp_error($participant_post_id)) {
            return false;
        }

        // Set create date
        $order_data['create_date'] = current_time('mysql');
        $order_data['update_date'] = current_time('mysql');
        $order_data['registration_status'] = 'pending';

        // Set default livestream access if livestream is enabled
        $livestream_enabled = \Alttag\Registrations\RegistrationContext::current()->isLivestreamEnabled();

        if ($livestream_enabled) {
            // Only apply filter if is_livestream_user is not already set (e.g., from import)
            if (!isset($order_data['is_livestream_user']) || $order_data['is_livestream_user'] === '') {
                $order_data['is_livestream_user'] = apply_filters(
                    'alttag_registrations_is_livestream_user',
                    false,
                    $order_data
                );
            }
            // Set default livestream_access only if not already set
            if (!isset($order_data['livestream_access']) || $order_data['livestream_access'] === '') {
                $order_data['livestream_access'] = LivestreamManager::NOT_GRANTED_STATE;
            }
        }

        // Set default language if multilingual and language not already set
        $ctx = \Alttag\Registrations\RegistrationContext::current();
        if ($ctx->hasPolylang() && empty($order_data['language'])) {
            if (function_exists('pll_current_language')) {
                $current_lang = pll_current_language('slug');
                if ($current_lang) {
                    $order_data['language'] = $current_lang;
                }
            }
            // Fallback to default language
            if (empty($order_data['language'])) {
                $order_data['language'] = \Alttag\Registrations\get_default_language();
            }
        }

        $order_data = apply_filters('alttag_registrations_participant_create_data', $order_data, $participant_post_id);

        // Update meta fields
        $metaFields = $this->getMetaFields();
        foreach ($metaFields as $key => $field) {
            if (isset($order_data[$key])) {
                update_post_meta($participant_post_id, $key, $order_data[$key]);
            }
        }

        $this->saveExtraOrderMeta($participant_post_id, $order_data);

        // Fire action before ticket generation so that customization plugins
        // can save product_id and other meta used by the ticket designer
        do_action('alttag_registrations_participant_create', $participant_post_id, $order_data);

        // Bust any ParticipantState cache populated by hooks above — otherwise
        // the ticket_url / qr_code_url baked into meta below can end up
        // pointing at a stale variable_symbol snapshot, producing an empty
        // ?hash= on the download link.
        \Alttag\Registrations\ParticipantState::clearCache($participant_post_id);

        // Generate and save QR code URL. Read variable_symbol from fresh meta
        // rather than $order_data — hooks fired above may have replaced it
        // (e.g. SuperFaktura filling in the invoice VS number).
        $current_vs = get_post_meta($participant_post_id, 'variable_symbol', true);
        if (!empty($current_vs) && $this->verificationManager) {
            $qr_code_url = $this->verificationManager->generateQRCodeUrl($current_vs);
            update_post_meta($participant_post_id, 'qr_code_url', $qr_code_url);

            // Generate ticket
            $this->verificationManager->generateAndSaveTicket($participant_post_id);
        }

        return $participant_post_id;
    }

    private function saveExtraOrderMeta($participant_id, array $order_data)
    {
        foreach (self::EXTRA_ORDER_META_KEYS as $key) {
            if (array_key_exists($key, $order_data)) {
                update_post_meta($participant_id, $key, $order_data[$key]);
            }
        }
    }

    /**
     * Update the date and time fields
     *
     * @param int $post_id The ID of the post
     * @param array $data The data of the post
     *
     * @return void
     */
    public function updateDateTimeFields($post_id, $data)
    {
        if ($data['post_type'] !== 'participant') {
            return;
        }

        // Set create_date for new posts
        if (!get_post_meta($post_id, 'create_date', true)) {
            update_post_meta($post_id, 'create_date', current_time('mysql'));
        }

        // Always update the update_date
        update_post_meta($post_id, 'update_date', current_time('mysql'));
    }

    /**
     * Get participant details
     *
     * @param int $participant_id The ID of the participant
     *
     * @return array|false The participant details or false if invalid
     */
    public function getParticipantDetails($participant_id)
    {
        $participant = get_post($participant_id);
        if (!$participant || $participant->post_type !== 'participant') {
            return false;
        }

        $meta_fields = $this->getMetaFields();
        $details = [
            'ID' => $participant_id,
            'title' => $participant->post_title,
            'status' => $participant->post_status,
        ];

        // Add all meta fields from ParticipantState (single query, cached)
        $state = \Alttag\Registrations\ParticipantState::get($participant_id);
        foreach (array_keys($meta_fields) as $key) {
            $value = $state ? $state->getMeta($key) : '';

            if (isset($meta_fields[$key]['type']) && $meta_fields[$key]['type'] === 'checkbox') {
                $value = \Alttag\Registrations\FieldBuilder::isTruthyValue($value);
            }
            $details[$key] = $value;
        }

        // Set ticket full file path. Guard the empty meta: concatenating an
        // empty relative path yields ABSPATH itself, which file_exists()
        // happily confirms (it is a directory) and which then travels into
        // wp_mail() as a zero-sized attachment.
        $details['ticket_full_file_path'] = !empty($details['ticket_file_path'])
            ? rtrim(ABSPATH, '/') . $details['ticket_file_path']
            : '';
        $details['country_name'] = $this->getCountryName($details['country']);

        // Allow filtering of participant details
        return apply_filters('alttag_registrations_participant_details', $details, $participant_id);
    }

    /**
     * Get the country name
     *
     * @param string $country_code The country code
     *
     * @return string The country name
     */
    public function getCountryName($country_code)
    {
        $countries = \Alttag\Registrations\get_woocommerce_countries();
        return $countries[$country_code] ?? $country_code;
    }

    /**
     * Prepare email attachments (ticket and invoice) for a participant
     *
     * @param array $participant Participant details from getParticipantDetails()
     * @param bool $include_ticket Whether to include ticket attachment
     * @param bool $include_invoice Whether to include invoice attachment
     * @return array Array of attachment file paths
     */
    public static function prepareEmailAttachments($participant, $include_ticket, $include_invoice)
    {
        $attachments = [];

        // Add ticket attachment
        if ($include_ticket) {
            $ticket_path = $participant['ticket_full_file_path'] ?? '';
            // is_file() + filesize(): file_exists() is true for directories
            // too, and a half-written PDF leaves a 0-byte file behind. Both
            // are rejected by mail providers ("Zero-sized attachments not
            // allowed" on Postmark), which fails the whole send.
            if (!empty($ticket_path) && is_file($ticket_path) && filesize($ticket_path) > 0) {
                $attachments[] = $ticket_path;
            }
        }

        // Add invoice attachment
        if ($include_invoice) {
            $invoice_url = $participant['invoice_url'] ?? '';
            $variable_symbol = $participant['variable_symbol'] ?? '';

            if (!empty($invoice_url)) {
                $upload_dir = wp_upload_dir();
                $invoice_content = @file_get_contents($invoice_url);

                // Validate we actually got a PDF. SuperFaktura (and other
                // remote invoice hosts) can respond with 200 OK carrying an
                // HTML login page when the invoice was deleted, its token
                // expired, or the session redirect kicks in. Attaching that
                // HTML under a `.pdf` filename triggers Gmail's content-
                // type-spoof security block (bounce: "552 5.7.0 potential
                // security issue"), and other providers silently strip or
                // corrupt the attachment. Guard: first 5 bytes must be the
                // PDF magic marker `%PDF-`.
                $is_pdf = is_string($invoice_content)
                    && strncmp($invoice_content, '%PDF-', 5) === 0;

                if ($invoice_content !== false && $is_pdf) {
                    $invoice_name = apply_filters(
                        'alttag_registrations_invoice_name',
                        __('invoice', 'alttag-registrations')
                    );
                    $invoice_filename = "{$invoice_name}_{$variable_symbol}.pdf";
                    $temp_invoice_path = $upload_dir['path'] . '/' . sanitize_file_name($invoice_filename);

                    if (wp_mkdir_p(dirname($temp_invoice_path))) {
                        // Only attach what actually made it to disk: a failed
                        // or partial write leaves a 0-byte file that mail
                        // providers reject outright.
                        $written = file_put_contents($temp_invoice_path, $invoice_content);
                        if ($written > 0) {
                            $attachments[] = $temp_invoice_path;
                        } else {
                            error_log(sprintf(
                                '[alttag-registrations] Could not write invoice attachment to "%s"'
                                . ' for participant with VS "%s" - skipping.',
                                $temp_invoice_path,
                                $variable_symbol
                            ));
                        }
                    }
                } elseif ($invoice_content !== false && !$is_pdf) {
                    error_log(sprintf(
                        '[alttag-registrations] Invoice URL "%s" returned non-PDF content'
                        . ' (first bytes: %s) — skipping attachment for participant with VS "%s".'
                        . ' Likely: invoice was deleted in SuperFaktura, token expired,'
                        . ' or session redirect.',
                        $invoice_url,
                        substr((string) $invoice_content, 0, 20),
                        $variable_symbol
                    ));
                } elseif ($invoice_content === false) {
                    error_log(sprintf(
                        '[alttag-registrations] Failed to fetch invoice URL "%s"'
                        . ' for participant with VS "%s".',
                        $invoice_url,
                        $variable_symbol
                    ));
                }
            }
        }

        return $attachments;
    }
}
