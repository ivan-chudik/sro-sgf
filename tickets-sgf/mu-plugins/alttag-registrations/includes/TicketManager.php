<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;

/**
 * Handles ticket generation, download, and serving functionality
 */
class TicketManager
{
    private $verificationManager;
    private $participantManager;
    private $ticketDesignerSettings;

    private function getDefaultTicketColumns(array $data, $variableSymbol, $participantId = 0)
    {
        // `$data` is participant meta; extras / trimmed participants often
        // omit optional fields (phone, business_id, tax_id, vat_id, price,
        // payment_method, …). Use nullish-coalesce everywhere so missing
        // keys don't spam debug.log with "Undefined array key" warnings.
        $columns = [
            'first_column' => [
                [
                    'key' => 'first_name',
                    'label' => __('First Name', 'alttag-registrations'),
                    'value' => $data['first_name'] ?? ''
                ],
                [
                    'key' => 'last_name',
                    'label' => __('Last Name', 'alttag-registrations'),
                    'value' => $data['last_name'] ?? ''
                ],
                [
                    'key' => 'company_name',
                    'label' => __('Company Name', 'alttag-registrations'),
                    'value' => $data['company_name'] ?? ''
                ],
                [
                    'key' => 'email',
                    'label' => __('Email', 'alttag-registrations'),
                    'value' => $data['email'] ?? ''
                ],
                [
                    'key' => 'phone',
                    'label' => __('Phone', 'alttag-registrations'),
                    'value' => $data['phone'] ?? ''
                ],
                [
                    'key' => 'city',
                    'label' => __('City', 'alttag-registrations'),
                    'value' => $data['city'] ?? ''
                ],
                [
                    'key' => 'country',
                    'label' => __('Country', 'alttag-registrations'),
                    'value' => $data['country'] ?? ''
                ],
                [
                    'key' => 'variable_symbol',
                    'label' => __('Variable Symbol', 'alttag-registrations'),
                    'value' => $variableSymbol
                ],
                [
                    'key' => 'used_coupons',
                    'label' => __('Used Coupons', 'alttag-registrations'),
                    'value' => !empty($data['used_coupons']) ? $data['used_coupons'] : __('None', 'alttag-registrations')
                ]
            ],
            'second_column' => [
                [
                    'key' => 'street',
                    'label' => __('Street', 'alttag-registrations'),
                    'value' => $data['street'] ?? ''
                ],
                [
                    'key' => 'zip',
                    'label' => __('ZIP', 'alttag-registrations'),
                    'value' => $data['zip'] ?? ''
                ],
                [
                    'key' => 'business_id',
                    'label' => __('Business ID', 'alttag-registrations'),
                    'value' => $data['business_id'] ?? ''
                ],
                [
                    'key' => 'tax_id',
                    'label' => __('Tax ID', 'alttag-registrations'),
                    'value' => $data['tax_id'] ?? ''
                ],
                [
                    'key' => 'vat_id',
                    'label' => __('VAT ID', 'alttag-registrations'),
                    'value' => $data['vat_id'] ?? ''
                ]
            ],
        ];

        $columns = apply_filters('alttag_registrations_ticket_default_fields', $columns, $data, $variableSymbol);

        return $this->filterHiddenDefaultTicketFields($columns, (int) $participantId);
    }

    private function filterHiddenDefaultTicketFields(array $columns, $participantId)
    {
        if (!$this->ticketDesignerSettings || $participantId <= 0) {
            return $columns;
        }

        $hiddenKeys = $this->ticketDesignerSettings->getHiddenDefaultTicketFieldsForParticipant($participantId);
        if (empty($hiddenKeys)) {
            return $columns;
        }

        foreach (['first_column', 'second_column'] as $columnKey) {
            if (empty($columns[$columnKey]) || !is_array($columns[$columnKey])) {
                continue;
            }

            $columns[$columnKey] = array_values(array_filter($columns[$columnKey], function ($field) use ($hiddenKeys) {
                return !in_array($field['key'] ?? '', $hiddenKeys, true);
            }));
        }

        return $columns;
    }

    private function applyFieldBuilderTicketData(array $pdf_data, array $data)
    {
        $ticket_data = FieldBuilder::getTicketData($data);

        if (!empty($ticket_data['first_column'])) {
            $pdf_data['first_column'] = $ticket_data['first_column'];
        }

        if (!empty($ticket_data['second_column'])) {
            $pdf_data['second_column'] = array_merge($ticket_data['second_column'], $pdf_data['second_column']);
        }

        return $pdf_data;
    }

    private function addSelectedDaysTicketData(array $pdf_data, $participantId)
    {
        $summary = RegistrationContext::forParticipant($participantId)->selectedDaysSummary();
        if ($summary === '') {
            return $pdf_data;
        }

        $pdf_data['second_column'][] = [
            'key' => 'selected_days',
            'label' => __('Selected days', 'alttag-registrations'),
            'value' => $summary,
        ];

        return $pdf_data;
    }

    private function getDefaultTicketFileName($participantId)
    {
        $ctx = RegistrationContext::forParticipant($participantId);
        $sku = $ctx->productSku();

        $ticket_word = __('ticket', 'alttag-registrations');

        if ($sku !== '') {
            return $sku . '-' . $ticket_word;
        }

        return $ticket_word;
    }

    public function registerHooks()
    {
        // Register ticket download handler
        add_action('parse_request', [$this, 'handleTicketDownloadRequest'], 1);

        // Add protection to main .htaccess
        add_action('init', function () {
            $this->protectPdfFiles();
        }, 5);

        // Temporary action to update existing ticket URLs
        add_action('init', [$this, 'updateExistingTicketUrls'], 20);
    }

    /**
     * Set the verification manager
     *
     * @param VerificationManager $verificationManager
     */
    public function setVerificationManager($verificationManager)
    {
        $this->verificationManager = $verificationManager;
    }

    /**
     * Set the participant manager
     *
     * @param ParticipantManager $participantManager
     */
    public function setParticipantManager($participantManager)
    {
        $this->participantManager = $participantManager;
    }

    /**
     * Set the ticket designer settings service
     *
     * @param TicketDesignerSettings $ticketDesignerSettings
     */
    public function setTicketDesignerSettings($ticketDesignerSettings)
    {
        $this->ticketDesignerSettings = $ticketDesignerSettings;
    }

    /**
     * Prepare PDF data for ticket generation
     *
     * @param int $participantId The ID of the participant
     * @param string $variableSymbol The variable symbol of the participant
     * @return array|false Array with PDF data and temp QR file path, or false on failure
     */
    public function preparePdfData($participantId, $variableSymbol)
    {
        // Set participant ID globally for language-specific ticket design settings
        $GLOBALS['current_ticket_participant_id'] = $participantId;

        // Set product ID globally for per-product ticket design settings
        // Don't override if already set (e.g., preview mode with specific product)
        $state = \Alttag\Registrations\ParticipantState::get($participantId);
        if (!isset($GLOBALS['current_ticket_product_id'])) {
            $product_id = $state ? $state->getMeta('product_id') : '';
            if (!empty($product_id)) {
                $GLOBALS['current_ticket_product_id'] = intval($product_id);
            }
        }

        ticket_designer_debug_log('ticket_manager.prepare_pdf', [
            'participant_id' => (int) $participantId,
            'variable_symbol' => (string) $variableSymbol,
            'participant_product_id' => $state ? (int) $state->getMeta('product_id') : 0,
            'participant_language' => $state ? $state->language() : '',
            'global_product_id' => isset($GLOBALS['current_ticket_product_id']) ? (int) $GLOBALS['current_ticket_product_id'] : 0,
        ]);

        // Switch to participant's language for ticket label translations
        do_action('alttag_registrations_before_ticket_generation', $participantId);

        // Get participant data
        $data = $this->verificationManager->formatCustomerData($participantId);
        if (!$data) {
            return false;
        }

        // Generate QR Code
        $verification_url = $this->verificationManager->getVerificationUrl($variableSymbol);
        $qrCode = QrCode::create($verification_url)
            ->setSize(300)
            ->setMargin(10)
            ->setErrorCorrectionLevel(new ErrorCorrectionLevelHigh());

        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        // Save QR code temporarily with unique filename
        $temp_qr = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qr_' . $variableSymbol . '_' . time() . '.png';
        $qr_content = $result->getString();
        if (file_put_contents($temp_qr, $qr_content) === false) {
            error_log('Failed to create QR code file: ' . $temp_qr);
            return false;
        }

        // Verify file exists and is readable
        if (!file_exists($temp_qr) || !is_readable($temp_qr)) {
            error_log('QR code file not readable: ' . $temp_qr);
            return false;
        }

        $default_columns = $this->getDefaultTicketColumns($data, $variableSymbol, $participantId);

        // Set up base PDF data structure
        $pdf_data = [
            'first_column' => $default_columns['first_column'],
            'second_column' => $default_columns['second_column'],
            'qr_code_data_url' => $temp_qr,
            'qr_code_description' => __('To verify your ticket, please scan the QR code at the entrance.', 'alttag-registrations'),
            'ticket_file_name' => apply_filters(
                'alttag_registrations_ticket_file_name',
                $this->getDefaultTicketFileName($participantId),
                $participantId,
                $data
            ),
            'variable_symbol' => $variableSymbol,
            'additional_data' => [
                'order_id'       => $data['order_id']       ?? '',
                'payment_method' => $data['payment_method'] ?? '',
                'price'          => $data['price']          ?? '',
                'create_date'    => $data['create_date']    ?? '',
            ]
        ];

        $pdf_data = $this->applyFieldBuilderTicketData($pdf_data, $data);
        $pdf_data = $this->addSelectedDaysTicketData($pdf_data, $participantId);

        // Apply filters to allow customization
        $pdf_data = apply_filters('alttag_registrations_pdf_ticket_data', $pdf_data, $data, $variableSymbol);

        // Apply admin-configured hide list to all sources (default + FB + session)
        if ($this->ticketDesignerSettings && $participantId > 0) {
            $hiddenKeys = $this->ticketDesignerSettings->getHiddenDefaultTicketFieldsForParticipant($participantId);
            if (!empty($hiddenKeys)) {
                foreach (['first_column', 'second_column'] as $_col) {
                    if (empty($pdf_data[$_col]) || !is_array($pdf_data[$_col])) {
                        continue;
                    }
                    $pdf_data[$_col] = array_values(array_filter($pdf_data[$_col], function ($f) use ($hiddenKeys) {
                        return !in_array($f['key'] ?? '', $hiddenKeys, true);
                    }));
                }
            }
        }

        // Apply admin-configured field order (from Ticket Designer). The saved
        // order also dictates which column each field belongs to, so a module
        // that pushed its field into first_column can still be moved to
        // second_column via the designer UI.
        if ($this->ticketDesignerSettings && $participantId > 0) {
            $order = $this->ticketDesignerSettings->getTicketFieldOrderForParticipant($participantId);
            $first_keys = $order['first_column'] ?? [];
            $second_keys = $order['second_column'] ?? [];

            if (!empty($first_keys) || !empty($second_keys)) {
                // Collapse keyed fields from BOTH columns into one map so we
                // can redistribute them. Keyless fields stay anchored to their
                // original column (no key = no way to address them in the UI).
                $by_key = [];
                $no_key = ['first_column' => [], 'second_column' => []];
                foreach (['first_column', 'second_column'] as $_col) {
                    if (empty($pdf_data[$_col]) || !is_array($pdf_data[$_col])) {
                        continue;
                    }
                    foreach ($pdf_data[$_col] as $field) {
                        $k = $field['key'] ?? '';
                        if ($k !== '') {
                            $by_key[$k] = ['field' => $field, 'origin' => $_col];
                        } else {
                            $no_key[$_col][] = $field;
                        }
                    }
                }

                $first_result = $no_key['first_column'];
                $second_result = $no_key['second_column'];

                foreach ($first_keys as $k) {
                    if (isset($by_key[$k])) {
                        $first_result[] = $by_key[$k]['field'];
                        unset($by_key[$k]);
                    }
                }
                foreach ($second_keys as $k) {
                    if (isset($by_key[$k])) {
                        $second_result[] = $by_key[$k]['field'];
                        unset($by_key[$k]);
                    }
                }
                // Keyed fields the admin has explicitly removed from both
                // saved column orders are treated as hidden. Previously the
                // designer's "uncheck to hide" UX was broken because any
                // unplaced keyed field was force-kept in its origin column.
                // To still let *truly new* FB fields surface automatically
                // (e.g. a fresh `ticket.enabled = true` flag) projects can
                // opt back into the legacy fallback via this filter.
                if (apply_filters('alttag_registrations_ticket_keep_unplaced_fields', false, $by_key)) {
                    foreach ($by_key as $entry) {
                        if ($entry['origin'] === 'second_column') {
                            $second_result[] = $entry['field'];
                        } else {
                            $first_result[] = $entry['field'];
                        }
                    }
                }

                $pdf_data['first_column'] = $first_result;
                $pdf_data['second_column'] = $second_result;
            }
        }

        // Note: variable_symbol visibility on the ticket is controlled via
        // the Ticket Designer "Ticket Fields" UI (hidden_default_fields).
        // The per-product _alttag_hide_variable_symbol meta is email-only.

        // Filter out empty fields from both columns to keep tickets clean
        if (!empty($pdf_data['first_column'])) {
            $pdf_data['first_column'] = array_filter($pdf_data['first_column'], function($field) {
                return !empty(trim((string) ($field['value'] ?? '')));
            });
        }

        if (!empty($pdf_data['second_column'])) {
            $pdf_data['second_column'] = array_filter($pdf_data['second_column'], function($field) {
                return !empty(trim((string) ($field['value'] ?? '')));
            });
        }

        // Auto-distribute populated fields evenly between columns while
        // KEEPING related fields together (name+surname, email+phone, address
        // block, company block, etc.). Empty ones are already stripped, so
        // the split reflects real content. The split point is chosen at a
        // group boundary closest to a 50/50 count split.
        if ($this->ticketDesignerSettings && $participantId > 0) {
            $ds = $this->ticketDesignerSettings->getResolvedSettingsForParticipant($participantId);
            if (!empty($ds['auto_field_layout'])) {
                $combined = array_values(array_merge(
                    array_values($pdf_data['first_column'] ?? []),
                    array_values($pdf_data['second_column'] ?? [])
                ));
                $n = count($combined);
                if ($n > 1) {
                    // Predefined semantic groups. Fields not listed here fall
                    // into a per-field "own group" — so they act as free split
                    // points but don't get grouped with anything else.
                    $groupsMap = apply_filters(
                        'alttag_registrations_ticket_field_groups',
                        [
                            'first_name' => 'name',       'last_name' => 'name',
                            'email' => 'contact',         'phone' => 'contact',
                            'street' => 'address',        'zip' => 'address',
                            'city' => 'address',          'country' => 'address',
                            'session_product' => 'session', 'session_date' => 'session',
                            'session_location' => 'session',
                            'company_name' => 'company',  'business_id' => 'company',
                            'tax_id' => 'company',        'vat_id' => 'company',
                            'variable_symbol' => 'admin', 'used_coupons' => 'admin',
                            'selected_days' => 'admin',
                        ]
                    );
                    $getGroup = function ($field) use ($groupsMap) {
                        $k = $field['key'] ?? '';
                        return isset($groupsMap[$k]) ? $groupsMap[$k] : ('_solo_' . $k);
                    };

                    // Collect boundary indexes where the group changes.
                    $boundaries = [0];
                    for ($i = 1; $i < $n; $i++) {
                        if ($getGroup($combined[$i - 1]) !== $getGroup($combined[$i])) {
                            $boundaries[] = $i;
                        }
                    }
                    $boundaries[] = $n;

                    // Pick the boundary closest to a balanced 50/50 count split.
                    // Reject 0 and $n (would leave one column empty) unless
                    // there really are no interior boundaries (one big group).
                    $target = (int) ceil($n / 2);
                    $interior = array_filter($boundaries, function ($b) use ($n) {
                        return $b > 0 && $b < $n;
                    });
                    if (empty($interior)) {
                        $bestB = $target; // fallback: hard split at midpoint
                    } else {
                        $bestB = null;
                        $bestDist = PHP_INT_MAX;
                        foreach ($interior as $b) {
                            $d = abs($b - $target);
                            if ($d < $bestDist) {
                                $bestDist = $d;
                                $bestB = $b;
                            }
                        }
                    }

                    $pdf_data['first_column']  = array_slice($combined, 0, $bestB);
                    $pdf_data['second_column'] = array_slice($combined, $bestB);
                }
            }
        }

        return [
            'pdf_data' => $pdf_data,
            'temp_qr_file' => $temp_qr
        ];
    }

    /**
     * Generate PDF ticket and save it to file
     *
     * @param int $participantId The ID of the participant
     * @param string $variableSymbol The variable symbol of the participant
     * @return string|false The path to the saved PDF file, or false on failure
     */
    public function generateAndSaveTicket($participantId, $variableSymbol)
    {
        // Prepare PDF data
        $prepared = $this->preparePdfData($participantId, $variableSymbol);
        if (!$prepared) {
            return false;
        }

        // Ensure PDF functions are loaded
        if (!function_exists('generate_tpdf')) {
            require_once(ALTTAG_REGISTRATIONS_PATH . '/lib/tfpdf/pdf.php');
        }

        // Generate PDF
        $result = generate_tpdf($prepared['pdf_data']);

        // Clean up temporary QR code file
        if (file_exists($prepared['temp_qr_file'])) {
            unlink($prepared['temp_qr_file']);
        }

        // Restore original language after ticket generation
        do_action('alttag_registrations_after_ticket_generation', $participantId);

        // Clean up globals
        unset($GLOBALS['current_ticket_participant_id']);
        unset($GLOBALS['current_ticket_product_id']);

        return $result;
    }

    /**
     * Generate PDF ticket as string (for preview/download)
     *
     * @param int $participantId The ID of the participant
     * @param string $variableSymbol The variable symbol of the participant
     * @return string|false The PDF content as string, or false on failure
     */
    public function generateTicketString($participantId, $variableSymbol)
    {
        // Prepare PDF data
        $prepared = $this->preparePdfData($participantId, $variableSymbol);
        if (!$prepared) {
            return false;
        }

        // Ensure PDF functions are loaded
        if (!function_exists('generate_tpdf_string')) {
            require_once(ALTTAG_REGISTRATIONS_PATH . '/lib/tfpdf/pdf.php');
        }

        // Generate PDF as string
        $pdf_content = generate_tpdf_string($prepared['pdf_data']);

        // Clean up temporary QR code file
        if (file_exists($prepared['temp_qr_file'])) {
            unlink($prepared['temp_qr_file']);
        }

        // Restore original language after ticket generation
        do_action('alttag_registrations_after_ticket_generation', $participantId);

        // Clean up globals
        unset($GLOBALS['current_ticket_participant_id']);
        unset($GLOBALS['current_ticket_product_id']);

        return $pdf_content;
    }

    /**
     * Handle ticket download request
     *
     * @param WP $wp The WordPress query object
     */
    public function handleTicketDownloadRequest($wp)
    {
        if (isset($wp->query_vars['download_ticket'])) {
            $variable_symbol = $wp->query_vars['download_ticket'];
            $provided_hash = isset($_GET['hash']) ? $_GET['hash'] : '';
            $this->serveTicketFile($variable_symbol, $provided_hash);
            exit;
        }
    }

    /**
     * Get the ticket URL with secure hash
     *
     * @param string $variableSymbol The variable symbol
     * @return string The ticket URL
     */
    public function getTicketUrl($variableSymbol)
    {
        // Generate a unique hash for this ticket
        $ticket_hash = $this->getTicketHash($variableSymbol);

        // Return a URL that will be handled by our PHP code
        return home_url("/" . ALTTAG_REGISTRATIONS_TICKET_DOWNLOAD_FOLDER . "/{$variableSymbol}?hash={$ticket_hash}");
    }

    /**
     * Generate a secure hash for ticket access
     *
     * @param string $variableSymbol The variable symbol
     * @return string The secure hash
     */
    private function getTicketHash($variableSymbol)
    {
        // Get participant ID from variable symbol
        $participant = $this->participantManager->getParticipantByVariableSymbol($variableSymbol);

        if (!$participant) {
            return '';
        }

        // Create a secure hash using participant ID, variable symbol, and secret
        $secret = wp_salt('auth');
        return hash_hmac('sha256', $participant->ID . $variableSymbol, $secret);
    }

    /**
     * Serve the ticket file if hash is valid
     *
     * @param string $variableSymbol The variable symbol of the participant
     * @param string $providedHash The provided hash of the ticket
     * @return void
     */
    public function serveTicketFile($variableSymbol, $providedHash)
    {
        // Verify the hash
        $expectedHash = $this->getTicketHash($variableSymbol);

        if (empty($expectedHash) || !hash_equals($expectedHash, $providedHash)) {
            status_header(403);
            wp_die(
                __('Access denied. Invalid ticket URL.', 'alttag-registrations'),
                __('Access Denied', 'alttag-registrations')
            );
            exit;
        }

        // Get the participant to verify it exists
        $participant = $this->participantManager->getParticipantByVariableSymbol($variableSymbol);
        if (!$participant) {
            status_header(404);
            wp_die(__('Participant not found.', 'alttag-registrations'), __('Not Found', 'alttag-registrations'));
            exit;
        }

        // Get the file path from participant meta
        $state = \Alttag\Registrations\ParticipantState::get($participant->ID);
        $relative_path = $state ? $state->ticket_file_path : '';
        if (!empty($relative_path)) {
            $file_path = rtrim(ABSPATH, '/') . $relative_path;
        } else {
            // Fallback to dynamic path for backwards compatibility
            $upload_dir = wp_upload_dir();
            $ticket_name = apply_filters('alttag_registrations_ticket_file_name', __('ticket', 'alttag-registrations'));
            $file_path = $upload_dir['basedir'] . '/qr-pdfs/' . $ticket_name . '_' . $variableSymbol . '.pdf';
        }

        // Check if file exists
        if (!file_exists($file_path)) {
            status_header(404);
            wp_die(__('Ticket not found.', 'alttag-registrations'), __('Not Found', 'alttag-registrations'));
            exit;
        }

        // Use the actual filename from the stored file path
        $filename = basename($file_path);

        // Serve the file
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        readfile($file_path);
        exit;
    }

    /**
     * Generate ticket with full workflow (PDF + meta updates)
     *
     * @param int $participantId The ID of the participant
     * @param string $variableSymbol The variable symbol
     * @return bool True if successful, false otherwise
     */
    public function generateTicketWithMeta($participantId, $variableSymbol)
    {
        try {
            // Generate PDF ticket and save it — returns the actual file path
            $ticket_file = $this->generateAndSaveTicket($participantId, $variableSymbol);
            if ($ticket_file) {
                // Convert absolute path to relative path from WordPress root
                $relative_path = $this->getRelativePathFromAbsolute($ticket_file);

                // Update ticket metadata
                update_post_meta($participantId, 'ticket_url', $this->getTicketUrl($variableSymbol));
                update_post_meta($participantId, 'ticket_file_path', $relative_path);

                // ParticipantState caches meta per request. Clear it after
                // updating ticket meta so follow-up email rendering uses fresh values.
                \Alttag\Registrations\ParticipantState::clearCache($participantId);

                return true;
            }
        } catch (\Exception $e) {
            error_log("Error generating ticket for participant {$participantId}: " . $e->getMessage());
            return false;
        }

        return false;
    }

    /**
     * Convert absolute file path to relative path from WordPress root
     *
     * @param string $absolute_path The absolute file path
     * @return string The relative path from WordPress root
     */
    private function getRelativePathFromAbsolute($absolute_path)
    {
        // Get WordPress root directory
        $wp_root = ABSPATH;

        // Replace backslashes with forward slashes for consistency
        $absolute_path = str_replace('\\', '/', $absolute_path);
        $wp_root = str_replace('\\', '/', $wp_root);

        // Remove WordPress root from the path
        $relative_path = str_replace($wp_root, '', $absolute_path);

        // Ensure the path starts with a slash
        if (substr($relative_path, 0, 1) !== '/') {
            $relative_path = '/' . $relative_path;
        }

        return $relative_path;
    }

    /**
     * Protect PDF files in main .htaccess
     *
     * @return bool True if successful, false otherwise
     */
    public function protectPdfFiles()
    {
        $htaccess_file = ABSPATH . '.htaccess';

        if (!file_exists($htaccess_file) || !is_writable($htaccess_file)) {
            error_log('Main .htaccess file not found or not writable: ' . $htaccess_file);
            return false;
        }

        $current_content = file_get_contents($htaccess_file);

        // Check if our rules are already there
        if (strpos($current_content, '# BEGIN PDF Protection') !== false) {
            return true; // Already protected
        }

        // Protection rules
        $protection_rules = <<<EOT
            # BEGIN PDF Protection
            <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteRule ^wp-content/uploads/qr-pdfs/.*\.pdf$ - [F,L]
            </IfModule>
            # END PDF Protection
        EOT;

        // Add our rules after WordPress rules
        $new_content = str_replace('# END WordPress', "# END WordPress\n" . $protection_rules, $current_content);

        // Write the file
        $result = file_put_contents($htaccess_file, $new_content);

        if ($result) {
            error_log('Added PDF protection rules to main .htaccess file');
            return true;
        } else {
            error_log('Failed to update main .htaccess file');
            return false;
        }
    }

    /**
     * Temporary function to update all existing ticket URLs with secure hashes
     *
     * @return void
     */
    public function updateExistingTicketUrls()
    {
        if (empty($_GET['update_ticket_urls'])) {
            return;
        }

        // Only run this for admin users to prevent it from running on every page load
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get all participants
        $args = array(
            'post_type' => 'participant',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        $participants = get_posts($args);
        $updated_count = 0;

        foreach ($participants as $participant_id) {
            $state = \Alttag\Registrations\ParticipantState::get($participant_id);
            $variable_symbol = $state ? $state->variable_symbol : '';

            if (empty($variable_symbol)) {
                continue;
            }

            // Generate new secure ticket URL
            $ticket_url = $this->getTicketUrl($variable_symbol);

            // Update the ticket URL
            update_post_meta($participant_id, 'ticket_url', $ticket_url);
            $updated_count++;
        }

        // Protect direct access to PDF files
        $this->protectPdfFiles();

        // Add an admin notice to inform about the update
        add_action('admin_notices', function () use ($updated_count) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php
                    printf(
                        __(
                            'Successfully updated %d ticket URLs with secure hashes and protected PDF directory. ' .
                            'Please remove the updateExistingTicketUrls function from TicketManager.php.',
                            'alttag-registrations'
                        ),
                        $updated_count
                    );
                    ?></p>
            </div>
            <?php
        });

        // Log the update
        error_log(sprintf('Updated %d ticket URLs with secure hashes.', $updated_count));
    }
}
