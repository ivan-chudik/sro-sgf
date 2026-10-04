<?php

namespace Alttag\Registrations\Participant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles export functionality for participants
 */
class Export
{
    private $manager;

    public function __construct(Manager $manager)
    {
        $enable = apply_filters('alttag_registrations_enable_participants', true);

        if (!$enable) {
            return;
        }

        $this->manager = $manager;

        // Add Excel export functionality
        add_filter('bulk_actions-edit-participant', [$this, 'registerExportBulkAction']);
        add_filter('handle_bulk_actions-edit-participant', [$this, 'handleExportBulkAction'], 10, 3);
        add_action('admin_notices', [$this, 'exportBulkActionNotice']);
        add_action('admin_post_export_all_participants', [$this, 'handleExportAllParticipants']);

        // Add Tickets ZIP export functionality
        add_action('admin_post_export_all_tickets', [$this, 'handleExportAllTickets']);

        // Export only the currently filtered subset — propagates every
        // filter $_GET param from the admin list URL through to the export.
        add_action('admin_post_export_filtered_participants', [$this, 'handleExportFilteredParticipants']);

        // Add single participant export (for import template)
        add_action('admin_post_export_single_participant', [$this, 'handleExportSingleParticipant']);
    }

    /**
     * Handle export single participant (template for import)
     *
     * @return void
     */
    public function handleExportSingleParticipant()
    {
        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'export_single_participant')) {
            wp_die(__('Security check failed', 'alttag-registrations'));
        }

        // Verify user capabilities
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to export participants', 'alttag-registrations'));
        }

        // Get participant ID
        $participant_id = isset($_POST['participant_id']) ? intval($_POST['participant_id']) : 0;
        if (!$participant_id) {
            wp_die(__('Invalid participant ID', 'alttag-registrations'));
        }

        // Add filter to exclude fields that are auto-generated during import
        // These fields should not be in the import template as they will be generated fresh
        add_filter('alttag_registrations_export_exclude_fields', function ($fields) {
            return array_merge($fields, [
                'order_id',
                'variable_symbol',
                'registration_status',
                'invoice_id',
                'invoice_url',
                'qr_code_url',
                'ticket_url',
                'ticket_file_path',
                'create_date',
                'update_date',
                'livestream_access',
                'price',
                'payment_method',
            ]);
        });

        // Generate and download Excel file
        $this->generateExcelFile([$participant_id]);
    }

    /**
     * Register the export bulk action
     *
     * @param array $bulk_actions Array of bulk actions
     *
     * @return array Modified array of bulk actions
     */
    public function registerExportBulkAction($bulk_actions)
    {
        $bulk_actions['export_to_excel'] = __('Export selected to Excel', 'alttag-registrations');
        $bulk_actions['download_tickets'] = __('Download selected tickets', 'alttag-registrations');
        return $bulk_actions;
    }

    /**
     * Handle the export bulk action
     *
     * @param string $redirect_to The redirect URL
     * @param string $doaction The action being taken
     * @param array $post_ids The items to take the action on
     *
     * @return string Modified redirect URL
     */
    public function handleExportBulkAction($redirect_to, $doaction, $post_ids)
    {
        if ($doaction === 'export_to_excel') {
            // Verify user capabilities
            if (!current_user_can('edit_posts')) {
                wp_die(__('You do not have permission to export participants', 'alttag-registrations'));
            }

            // Generate and download Excel file
            $this->generateExcelFile($post_ids);

            return add_query_arg('exported_participants', count($post_ids), $redirect_to);
        }

        if ($doaction === 'download_tickets') {
            // Verify user capabilities
            if (!current_user_can('edit_posts')) {
                wp_die(__('You do not have permission to download tickets', 'alttag-registrations'));
            }

            if (empty($post_ids)) {
                wp_die(__('No participants selected for ticket download', 'alttag-registrations'));
            }

            // Create ZIP archive of tickets for selected participants
            $this->createTicketsZipArchive($post_ids);

            return add_query_arg('downloaded_tickets', count($post_ids), $redirect_to);
        }

        return $redirect_to;
    }

    /**
     * Display admin notice after export
     *
     * @return void
     */
    public function exportBulkActionNotice()
    {
        if (!empty($_REQUEST['exported_participants'])) {
            $count = intval($_REQUEST['exported_participants']);
            $message = sprintf(
                _n(
                    'Exported %s participant to Excel.',
                    'Exported %s participants to Excel.',
                    $count,
                    'alttag-registrations'
                ),
                number_format_i18n($count)
            );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }

        if (!empty($_REQUEST['downloaded_tickets'])) {
            $count = intval($_REQUEST['downloaded_tickets']);
            $message = sprintf(
                _n(
                    'Downloaded %s participant ticket.',
                    'Downloaded %s participant tickets.',
                    $count,
                    'alttag-registrations'
                ),
                number_format_i18n($count)
            );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }

    /**
     * Generate Excel file with participant data
     *
     * @param array $participant_ids Array of participant IDs to include in the export
     *
     * @return void
     */
    public function generateExcelFile($participant_ids)
    {
        if (empty($participant_ids)) {
            wp_die(__('No participants selected for export', 'alttag-registrations'));
        }

        // Check if PhpSpreadsheet is available
        if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            // If not available, try to load it from composer
            $autoload_file = ALTTAG_REGISTRATIONS_PATH . '/vendor/autoload.php';
            if (file_exists($autoload_file)) {
                require_once $autoload_file;
            } else {
                wp_die(__('PhpSpreadsheet library is not available. Please install it using Composer.', 'alttag-registrations'));
            }
        }

        try {
            // Create new Spreadsheet object
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Get meta fields for labels
            $metaFields = $this->manager->getMetaFields();

            // Fields to exclude from export
            // The participant ids are passed along so a listener can decide per
            // export: a column that is empty for everyone in this file is noise,
            // but the same column is the point of the export in another context.
            $exclude_fields = apply_filters('alttag_registrations_export_exclude_fields', [
                'registration_history',
                'qr_code_img'
            ], $participant_ids);

            // Define the preferred order of fields for the export
            $preferred_order = apply_filters('alttag_registrations_export_field_order', ['variable_symbol']);

            // Create export fields array with the preferred order
            $export_fields = [];

            // First add fields in the preferred order
            foreach ($preferred_order as $key) {
                if (isset($metaFields[$key]) && !in_array($key, $exclude_fields)) {
                    $export_fields[$key] = $metaFields[$key]['admin_label'] ?? $metaFields[$key]['label'];
                }
            }

            // Then add any remaining fields not in the preferred order
            foreach ($metaFields as $key => $field) {
                if (!in_array($key, $exclude_fields) && !isset($export_fields[$key])) {
                    $export_fields[$key] = $field['admin_label'] ?? $field['label'];
                }
            }

            // Allow third-party plugins to modify the export fields
            $export_fields = apply_filters('alttag_registrations_export_fields', $export_fields, $metaFields, $participant_ids);

            // Generate column letters (A-Z, then AA, AB, etc.)
            $columns = range('A', 'Z');
            for ($i = 0; $i < 26 && count($columns) <= 100; $i++) {
                for ($j = 0; $j < 26 && count($columns) <= 100; $j++) {
                    $columns[] = $columns[$i] . $columns[$j];
                }
            }

            // Add headers to the first row
            $col_index = 0;
            foreach ($export_fields as $key => $label) {
                $sheet->setCellValue($columns[$col_index] . '1', $label);
                $col_index++;
            }

            // Add data
            $row = 2;
            foreach ($participant_ids as $participant_id) {
                $participant = $this->manager->getParticipantDetails($participant_id);
                if (!$participant) {
                    continue;
                }

                // Allow third-party plugins to modify the participant data before export
                $participant = apply_filters('alttag_registrations_export_participant_data', $participant, $participant_id);

                // Let modules expand a participant into multiple rows. The
                // hotel module uses this to emit one row per booked night so
                // multi-night customers aren't collapsed to a single line.
                $participant_rows = apply_filters(
                    'alttag_registrations_export_participant_rows',
                    [$participant],
                    $participant_id
                );
                if (!is_array($participant_rows) || empty($participant_rows)) {
                    $participant_rows = [$participant];
                }

                foreach ($participant_rows as $row_data) {
                    $col_index = 0;
                    foreach ($export_fields as $key => $label) {
                        $value = $row_data[$key] ?? '';

                        // Format special fields
                        if ($key === 'country' && !empty($value)) {
                            $countries = $this->getWoocommerceCountries();
                            $value = isset($countries[$value]) ? $countries[$value] : $value;
                        } elseif (in_array($key, ['create_date', 'update_date']) && !empty($value)) {
                            $value = date('Y-m-d H:i:s', strtotime($value));
                        } elseif ($key === 'language') {
                            // Format language value - use default if empty
                            $default_lang = \Alttag\Registrations\get_default_language();
                            $value = !empty($value) ? strtoupper($value) : strtoupper($default_lang);
                        } else {
                            // Generic select / multiselect → resolve value(s) to
                            // their option labels so the spreadsheet shows
                            // "Topic A, Topic B" instead of raw "topic_a, topic_b".
                            $field_type = $metaFields[$key]['type'] ?? '';
                            $options = $metaFields[$key]['options'] ?? null;
                            $is_choice = in_array($field_type, ['select', 'multiselect'], true)
                                && is_array($options);
                            if ($is_choice && is_array($value)) {
                                $mapped = array_map(static function ($v) use ($options) {
                                    $k = (string) $v;
                                    return $options[$k] ?? $v;
                                }, $value);
                                $value = implode(', ', array_filter(array_map('strval', $mapped)));
                            } elseif ($is_choice && $value !== '' && isset($options[(string) $value])) {
                                $value = $options[(string) $value];
                            }
                        }

                        // Allow third-party plugins to modify the cell value before export
                        $value = apply_filters(
                            'alttag_registrations_export_cell_value',
                            $value,
                            $key,
                            $row_data,
                            $participant_id
                        );

                        // Ensure scalar value for spreadsheet (arrays/objects → JSON string)
                        if (is_array($value) || is_object($value)) {
                            $value = wp_json_encode($value);
                        }

                        $sheet->setCellValue($columns[$col_index] . $row, $value);
                        $col_index++;
                    }

                    $row++;
                }
            }

            // Auto-size columns
            for ($col = 0; $col < count($export_fields); $col++) {
                if (isset($columns[$col])) {
                    $sheet->getColumnDimension($columns[$col])->setAutoSize(true);
                }
            }

            // Allow third-party plugins to modify the spreadsheet before saving
            $spreadsheet = apply_filters('alttag_registrations_export_spreadsheet', $spreadsheet, $participant_ids);

            // Create writer
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

            // Set headers for download
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="participants_export_' . date('Y-m-d_H-i-s') . '.xlsx"');
            header('Cache-Control: max-age=0');

            // Save to PHP output
            $writer->save('php://output');
            exit;
        } catch (\Exception $e) {
            error_log('Excel Export Error: ' . $e->getMessage() . ' - ' . $e->getTraceAsString());

            wp_die(sprintf(
                __('Error generating Excel file: %s', 'alttag-registrations'),
                $e->getMessage()
            ));
        }
    }

    /**
     * Get WooCommerce countries
     *
     * @return array Array of countries
     */
    private function getWoocommerceCountries()
    {
        return \Alttag\Registrations\get_woocommerce_countries();
    }

    /**
     * Handle export all participants
     *
     * @return void
     */
    public function handleExportAllParticipants()
    {
        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'export_all_participants')) {
            wp_die(__('Security check failed', 'alttag-registrations'));
        }

        // Verify user capabilities
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to export participants', 'alttag-registrations'));
        }

        // Get all participant IDs
        $args = [
            'post_type' => 'participant',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ];

        // Add registration status filter if provided
        if (!empty($_GET['registration_status'])) {
            $args['meta_query'] = [
                [
                    'key' => 'registration_status',
                    'value' => sanitize_text_field($_GET['registration_status']),
                ],
            ];
        }

        $participant_ids = get_posts($args);

        if (empty($participant_ids)) {
            wp_die(__('No participants found for export', 'alttag-registrations'));
        }

        // Generate and download Excel file
        $this->generateExcelFile($participant_ids);
    }

    /**
     * Handle export of the currently filtered participant subset. Uses the
     * same filter parsing as the on-screen list (AdminUI::buildQueryArgsFromFilters)
     * so the exported file mirrors exactly what the admin sees.
     */
    public function handleExportFilteredParticipants()
    {
        if (!isset($_GET['_wpnonce'])
            || !wp_verify_nonce($_GET['_wpnonce'], 'export_filtered_participants')) {
            wp_die(__('Security check failed', 'alttag-registrations'));
        }
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to export participants', 'alttag-registrations'));
        }

        $filter_args = \Alttag\Registrations\Participant\AdminUI::buildQueryArgsFromFilters();

        $query_args = [
            'post_type' => 'participant',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ];
        if (!empty($filter_args['meta_query'])) {
            $query_args['meta_query'] = $filter_args['meta_query'];
        }
        if (!empty($filter_args['date_query'])) {
            $query_args['date_query'] = $filter_args['date_query'];
        }

        $participant_ids = get_posts($query_args);
        if (empty($participant_ids)) {
            wp_die(__('No participants match the current filters.', 'alttag-registrations'));
        }

        $this->generateExcelFile($participant_ids);
    }

    /**
     * Handle export all tickets
     *
     * @return void
     */
    public function handleExportAllTickets()
    {
        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'export_all_tickets')) {
            wp_die(__('Security check failed', 'alttag-registrations'));
        }

        // Verify user capabilities
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to download tickets', 'alttag-registrations'));
        }

        // Get all participant IDs
        $args = [
            'post_type' => 'participant',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ];

        // Add registration status filter if provided
        if (!empty($_GET['registration_status'])) {
            $args['meta_query'] = [
                [
                    'key' => 'registration_status',
                    'value' => sanitize_text_field($_GET['registration_status']),
                ],
            ];
        }

        $participant_ids = get_posts($args);

        if (empty($participant_ids)) {
            wp_die(__('No participants found for ticket download', 'alttag-registrations'));
        }

        // Create ZIP archive of tickets
        $this->createTicketsZipArchive($participant_ids);
    }

    /**
     * Get absolute path from relative path
     *
     * @param string $relative_path The relative path from WordPress root
     * @return string The absolute file path
     */
    private function getAbsolutePathFromRelative($relative_path)
    {
        return \Alttag\Registrations\get_absolute_path($relative_path);
    }

    /**
     * Create ZIP archive of tickets
     *
     * @param array $participant_ids Array of participant IDs
     *
     * @return void
     */
    public function createTicketsZipArchive($participant_ids)
    {
        // Check if ZipArchive is available
        if (!class_exists('ZipArchive')) {
            wp_die(__('ZipArchive extension is not available on this server.', 'alttag-registrations'));
        }

        $upload_dir = wp_upload_dir();
        $zip_file = $upload_dir['basedir'] . '/tickets_' . date('Y-m-d_H-i-s') . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($zip_file, \ZipArchive::CREATE) !== true) {
            wp_die(__('Could not create ZIP file', 'alttag-registrations'));
        }

        $ticket_count = 0;
        $ticket_name = apply_filters('alttag_registrations_ticket_file_name', __('ticket', 'alttag-registrations'));
        $qr_pdfs_path = \Alttag\Registrations\get_qr_pdfs_path();

        foreach ($participant_ids as $participant_id) {
            $participant = $this->manager->getParticipantDetails($participant_id);
            if (!$participant || empty($participant['variable_symbol'])) {
                continue;
            }

            // Try to get the ticket file path from ParticipantState
            $state = \Alttag\Registrations\ParticipantState::get($participant_id);
            $relative_path = $state ? $state->ticket_file_path : '';
            
            if (!empty($relative_path)) {
                // Convert relative path to absolute path
                $ticket_file = $this->getAbsolutePathFromRelative($relative_path);
            } else {
                // If no file path in metadata, try to construct it
                $ticket_file = $qr_pdfs_path . '/' . $ticket_name . '_' . $participant['variable_symbol'] . '.pdf';
            }

            // Apply a filter to allow custom ticket file path resolution
            $ticket_file = apply_filters('alttag_registrations_ticket_file_path', $ticket_file, $participant, $participant_id);

            if (file_exists($ticket_file)) {
                // Use the original filename from the file path
                $filename = basename($ticket_file);
                
                // Allow customization of the filename via filter
                $filename = apply_filters(
                    'alttag_registrations_ticket_zip_filename',
                    $filename,
                    $participant,
                    $participant_id
                );
                
                $zip->addFile($ticket_file, $filename);
                $ticket_count++;
            } else {
                // Log that we couldn't find the ticket file
                error_log(sprintf(
                    'Could not find ticket file for participant #%d (variable symbol: %s). Tried path: %s',
                    $participant_id,
                    $participant['variable_symbol'],
                    $ticket_file
                ));
            }
        }

        $zip->close();

        if ($ticket_count === 0) {
            unlink($zip_file);
            wp_die(__('No ticket files found for the selected participants', 'alttag-registrations'));
        }

        // Set headers for download
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="tickets_' . date('Y-m-d_H-i-s') . '.zip"');
        header('Content-Length: ' . filesize($zip_file));
        header('Pragma: no-cache');
        header('Expires: 0');

        // Output file
        readfile($zip_file);

        // Delete the temporary file
        unlink($zip_file);
        exit;
    }
}
