<?php

namespace Alttag\Registrations\Participant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles import functionality for participants from Excel files.
 *
 * Provides a two-step import process:
 * 1. Preview - parses file, categorizes participants (new/update/errors)
 * 2. Execute - creates new participants or updates existing ones
 *
 * Features:
 * - Matches participants by email address
 * - Supports Slovak and English column headers
 * - Generates QR codes and tickets for new participants
 * - Optionally sends confirmation emails
 * - Tracks all changes in registration history
 */
class Import
{
    /** @var string Prefix for import-related transients */
    private const TRANSIENT_PREFIX = 'alttag_import_';

    /** @var int Transient expiration time in seconds (30 minutes) */
    private const TRANSIENT_EXPIRY = 1800;

    /** @var Manager */
    private $manager;

    /** @var Email|null */
    private $email;

    /**
     * Initialize import functionality.
     *
     * @param Manager $manager Participant manager instance
     * @param Email|null $email Email handler for sending confirmations
     */
    public function __construct(Manager $manager, ?Email $email = null)
    {
        if (!apply_filters('alttag_registrations_enable_participants', true)) {
            return;
        }

        $this->manager = $manager;
        $this->email = $email;

        add_action('admin_menu', [$this, 'addImportMenuPage']);
        add_action('admin_post_preview_participant_import', [$this, 'handlePreviewImport']);
        add_action('admin_post_execute_participant_import', [$this, 'handleExecuteImport']);
        add_action('admin_post_cancel_participant_import', [$this, 'handleCancelImport']);
    }

    /**
     * Handle import cancellation - clears transients and redirects back.
     */
    public function handleCancelImport(): void
    {
        $this->verifyNonce($_GET['_wpnonce'] ?? '', 'cancel_participant_import');
        $this->clearImportTransients();

        wp_redirect(add_query_arg('import_cancelled', 1, $this->getImportPageUrl()));
        exit;
    }

    /**
     * Set the email handler (used for dependency injection).
     */
    public function setEmail(Email $email): void
    {
        $this->email = $email;
    }

    /**
     * Register the import submenu page under Participants.
     */
    public function addImportMenuPage(): void
    {
        add_submenu_page(
            'edit.php?post_type=participant',
            __('Import Participants', 'alttag-registrations'),
            __('Import', 'alttag-registrations'),
            'manage_options',
            'alttag-import',
            [$this, 'renderImportPage']
        );
    }

    /**
     * Render the import page - shows upload form or preview based on state.
     */
    public function renderImportPage(): void
    {
        $preview_data = $this->getPreviewTransient();

        if ($preview_data) {
            $this->renderPreviewPage($preview_data);
            return;
        }

        $this->renderUploadForm();
    }

    /**
     * Render the initial upload form for selecting Excel file and import options.
     */
    private function renderUploadForm(): void
    {
        ?>
        <div class="wrap">
            <h1><?php _e('Import Participants', 'alttag-registrations'); ?></h1>

            <?php $this->displayNotices(); ?>

            <div class="notice notice-info">
                <p>
                    <strong><?php _e('Import Format:', 'alttag-registrations'); ?></strong>
                    <?php _e('Upload an Excel file (.xlsx) with the same structure as the export file. The import will match participants by email address.', 'alttag-registrations'); ?>
                </p>
                <p>
                    <strong><?php _e('Required columns:', 'alttag-registrations'); ?></strong>
                    <?php _e('Email', 'alttag-registrations'); ?>
                </p>
            </div>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="preview_participant_import">
                <?php wp_nonce_field('preview_participant_import', 'import_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="import_file"><?php _e('Excel File', 'alttag-registrations'); ?></label>
                        </th>
                        <td>
                            <input type="file" name="import_file" id="import_file" accept=".xlsx" required>
                            <p class="description">
                                <?php _e('Select an Excel file (.xlsx) exported from this system or with matching column structure.', 'alttag-registrations'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="send_emails"><?php _e('Send Emails', 'alttag-registrations'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="send_emails" id="send_emails" value="1" checked>
                                <?php _e('Send confirmation email with ticket to new participants', 'alttag-registrations'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="default_status"><?php _e('Default Registration Status', 'alttag-registrations'); ?></label>
                        </th>
                        <td>
                            <select name="default_status" id="default_status">
                                <option value="pending"><?php _e('Pending', 'alttag-registrations'); ?></option>
                                <option value="confirmed"><?php _e('Confirmed', 'alttag-registrations'); ?></option>
                            </select>
                            <p class="description">
                                <?php _e('Status to use for new participants if not specified in the file.', 'alttag-registrations'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="default_language"><?php _e('Default Language', 'alttag-registrations'); ?></label>
                        </th>
                        <td>
                            <?php if (\Alttag\Registrations\RegistrationContext::current()->isMultilingual()): ?>
                                <select name="default_language" id="default_language">
                                    <?php
                                    $slugs = pll_languages_list(['fields' => 'slug']);
                                    $default_lang = \Alttag\Registrations\get_default_language();
                                    foreach ($slugs as $slug):
                                        $lang = \PLL()->model->get_language($slug);
                                        if ($lang):
                                    ?>
                                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($slug, $default_lang); ?>>
                                            <?php echo esc_html($lang->name); ?>
                                        </option>
                                    <?php
                                        endif;
                                    endforeach;
                                    ?>
                                </select>
                            <?php else: ?>
                                <input type="text" name="default_language" id="default_language" value="sk" class="small-text">
                            <?php endif; ?>
                            <p class="description">
                                <?php _e('Language to use for new participants if not specified in the file.', 'alttag-registrations'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="submit" class="button button-primary" value="<?php _e('Preview Import', 'alttag-registrations'); ?>">
                    <a href="<?php echo admin_url('edit.php?post_type=participant'); ?>" class="button"><?php _e('Cancel', 'alttag-registrations'); ?></a>
                </p>
            </form>

            <hr>

            <h2><?php _e('Download Template', 'alttag-registrations'); ?></h2>
            <p><?php _e('To get the correct file format, export existing participants:', 'alttag-registrations'); ?></p>
            <p>
                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=export_all_participants'), 'export_all_participants'); ?>" class="button">
                    <?php _e('Export All Participants', 'alttag-registrations'); ?>
                </a>
            </p>

            <p style="margin-top: 15px;">
                <strong><?php _e('Or export a single participant as template:', 'alttag-registrations'); ?></strong>
            </p>
            <p>
                <?php
                $participants = get_posts([
                    'post_type' => 'participant',
                    'posts_per_page' => 100,
                    'orderby' => 'date',
                    'order' => 'DESC',
                ]);
                if (!empty($participants)) :
                ?>
                <select id="export_single_participant" style="min-width: 300px;">
                    <option value=""><?php _e('-- Select participant --', 'alttag-registrations'); ?></option>
                    <?php foreach ($participants as $p) :
                        $pState = \Alttag\Registrations\ParticipantState::get($p->ID);
                        $email = $pState ? $pState->email : '';
                        $first_name = $pState ? $pState->first_name : '';
                        $last_name = $pState ? $pState->last_name : '';
                    ?>
                        <option value="<?php echo esc_attr($p->ID); ?>">
                            <?php echo esc_html($first_name . ' ' . $last_name . ' (' . $email . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <a href="#" id="export_single_btn" class="button">
                    <?php _e('Export Selected', 'alttag-registrations'); ?>
                </a>

                <script>
                jQuery(document).ready(function($) {
                    $('#export_single_btn').on('click', function(e) {
                        e.preventDefault();
                        var participantId = $('#export_single_participant').val();
                        if (!participantId) {
                            alert('<?php echo esc_js(__('Please select a participant', 'alttag-registrations')); ?>');
                            return;
                        }
                        var form = $('<form>', {
                            'method': 'POST',
                            'action': '<?php echo admin_url('admin-post.php'); ?>'
                        });
                        form.append($('<input>', {'type': 'hidden', 'name': 'action', 'value': 'export_single_participant'}));
                        form.append($('<input>', {'type': 'hidden', 'name': 'participant_id', 'value': participantId}));
                        form.append($('<input>', {'type': 'hidden', 'name': '_wpnonce', 'value': '<?php echo wp_create_nonce('export_single_participant'); ?>'}));
                        $('body').append(form);
                        form.submit();
                    });
                });
                </script>
                <?php else : ?>
                <em><?php _e('No participants available for export.', 'alttag-registrations'); ?></em>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render the preview page showing categorized participants before import.
     *
     * @param array $preview_data Contains 'new', 'update', 'errors', and 'options' arrays
     */
    private function renderPreviewPage(array $preview_data): void
    {
        $new_participants = $preview_data['new'] ?? [];
        $update_participants = $preview_data['update'] ?? [];
        $skipped_participants = $preview_data['skipped'] ?? [];
        $errors = $preview_data['errors'] ?? [];
        $options = $preview_data['options'] ?? [];

        ?>
        <div class="wrap">
            <h1><?php _e('Import Preview', 'alttag-registrations'); ?></h1>

            <?php $this->displayNotices(); ?>

            <div class="notice notice-warning">
                <p>
                    <strong><?php _e('Review the changes below before confirming the import.', 'alttag-registrations'); ?></strong>
                </p>
            </div>

            <!-- Summary -->
            <div class="card" style="max-width: 100%; margin-bottom: 20px;">
                <h2><?php _e('Import Summary', 'alttag-registrations'); ?></h2>
                <table class="widefat" style="width: auto;">
                    <tr>
                        <td><strong><?php _e('New participants to create:', 'alttag-registrations'); ?></strong></td>
                        <td><span class="dashicons dashicons-plus-alt2" style="color: green;"></span> <?php echo count($new_participants); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('Existing participants to update:', 'alttag-registrations'); ?></strong></td>
                        <td><span class="dashicons dashicons-update" style="color: blue;"></span> <?php echo count($update_participants); ?></td>
                    </tr>
                    <?php if (!empty($skipped_participants)): ?>
                    <tr>
                        <td><strong><?php _e('Existing without changes (skipped):', 'alttag-registrations'); ?></strong></td>
                        <td><span class="dashicons dashicons-minus" style="color: #888;"></span> <?php echo count($skipped_participants); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($errors)): ?>
                    <tr>
                        <td><strong><?php _e('Rows with errors (skipped):', 'alttag-registrations'); ?></strong></td>
                        <td><span class="dashicons dashicons-warning" style="color: red;"></span> <?php echo count($errors); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td><strong><?php _e('Send emails to new participants:', 'alttag-registrations'); ?></strong></td>
                        <td><?php echo $options['send_emails'] ? __('Yes', 'alttag-registrations') : __('No', 'alttag-registrations'); ?></td>
                    </tr>
                </table>
            </div>

            <?php if (!empty($errors)): ?>
            <!-- Errors -->
            <div class="card" style="max-width: 100%; margin-bottom: 20px; border-left-color: #dc3232;">
                <h2 style="color: #dc3232;"><?php _e('Errors (these rows will be skipped)', 'alttag-registrations'); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php _e('Row', 'alttag-registrations'); ?></th>
                            <th><?php _e('Email', 'alttag-registrations'); ?></th>
                            <th><?php _e('Error', 'alttag-registrations'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($errors as $error): ?>
                        <tr>
                            <td><?php echo esc_html($error['row']); ?></td>
                            <td><?php echo esc_html($error['email'] ?? '-'); ?></td>
                            <td><?php echo esc_html($error['message']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($new_participants)): ?>
            <!-- New Participants -->
            <?php
            // Define preview columns - allow customization
            $preview_columns = apply_filters('alttag_registrations_import_preview_columns', [
                'first_name' => __('First Name', 'alttag-registrations'),
                'last_name' => __('Last Name', 'alttag-registrations'),
                'email' => __('Email', 'alttag-registrations'),
                'phone' => __('Phone', 'alttag-registrations'),
                'company_name' => __('Company', 'alttag-registrations'),
                'language' => __('Language', 'alttag-registrations'),
                'used_coupons' => __('Promo Code', 'alttag-registrations'),
                'registration_status' => __('Status', 'alttag-registrations'),
            ]);
            ?>
            <div class="card" style="max-width: 100%; margin-bottom: 20px; border-left-color: #46b450;">
                <h2 style="color: #46b450;">
                    <span class="dashicons dashicons-plus-alt2"></span>
                    <?php _e('New Participants', 'alttag-registrations'); ?> (<?php echo count($new_participants); ?>)
                </h2>
                <div style="overflow-x: auto;">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <?php foreach ($preview_columns as $key => $label): ?>
                            <th><?php echo esc_html($label); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($new_participants as $participant): ?>
                        <tr>
                            <?php foreach ($preview_columns as $key => $label):
                                $value = $participant[$key] ?? '';
                                // Special formatting for certain fields
                                if ($key === 'language') {
                                    $value = strtoupper($value ?: $options['default_language']);
                                } elseif ($key === 'registration_status') {
                                    $value = $value ?: $options['default_status'];
                                }
                                // Allow custom cell rendering
                                $cell_html = apply_filters('alttag_registrations_import_preview_cell', null, $key, $value, $participant);
                                if ($cell_html !== null) {
                                    echo '<td>' . $cell_html . '</td>';
                                } else {
                                    echo '<td>' . esc_html($value ?: '-') . '</td>';
                                }
                            endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($update_participants)): ?>
            <!-- Update Participants -->
            <div class="card" style="max-width: 100%; margin-bottom: 20px; border-left-color: #0073aa;">
                <h2 style="color: #0073aa;">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Participants to Update', 'alttag-registrations'); ?> (<?php echo count($update_participants); ?>)
                </h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php _e('Email', 'alttag-registrations'); ?></th>
                            <th><?php _e('Current Name', 'alttag-registrations'); ?></th>
                            <th><?php _e('Changes', 'alttag-registrations'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($update_participants as $participant): ?>
                        <tr>
                            <td><?php echo esc_html($participant['email']); ?></td>
                            <td><?php echo esc_html($participant['current_name']); ?></td>
                            <td>
                                <?php
                                $changes = [];
                                foreach ($participant['changes'] as $field => $change) {
                                    $changes[] = sprintf(
                                        '<strong>%s:</strong> %s → %s',
                                        esc_html($field),
                                        esc_html($change['old'] ?: '(empty)'),
                                        esc_html($change['new'])
                                    );
                                }
                                echo implode('<br>', $changes);
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($skipped_participants)): ?>
            <!-- Skipped Participants -->
            <div class="card" style="max-width: 100%; margin-bottom: 20px; border-left-color: #888;">
                <h2 style="color: #888;">
                    <span class="dashicons dashicons-minus"></span>
                    <?php _e('Skipped (no changes)', 'alttag-registrations'); ?>
                    (<?php echo count($skipped_participants); ?>)
                </h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php _e('Email', 'alttag-registrations'); ?></th>
                            <th><?php _e('Name', 'alttag-registrations'); ?></th>
                            <th><?php _e('Reason', 'alttag-registrations'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($skipped_participants as $participant): ?>
                        <tr>
                            <td><?php echo esc_html($participant['email']); ?></td>
                            <td><?php echo esc_html($participant['name']); ?></td>
                            <td><?php echo esc_html($participant['reason']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (empty($new_participants) && empty($update_participants)): ?>
            <div class="notice notice-info">
                <p><?php _e('No new participants to create or existing participants to update.', 'alttag-registrations'); ?></p>
            </div>
            <p class="submit">
                <a href="<?php echo esc_url(wp_nonce_url(
                    admin_url('admin-post.php?action=cancel_participant_import'),
                    'cancel_participant_import'
                )); ?>" class="button button-primary"><?php _e('Back to Upload', 'alttag-registrations'); ?></a>
            </p>
            <?php else: ?>
            <!-- Confirm Form -->
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="execute_participant_import">
                <?php wp_nonce_field('execute_participant_import', 'import_nonce'); ?>

                <p class="submit">
                    <input type="submit" name="confirm_import" class="button button-primary button-hero"
                           value="<?php _e('Confirm Import', 'alttag-registrations'); ?>"
                           onclick="return confirm('<?php esc_attr_e('Are you sure you want to proceed with the import?', 'alttag-registrations'); ?>');">
                    <a href="<?php echo admin_url('admin-post.php?action=cancel_participant_import&_wpnonce=' . wp_create_nonce('cancel_participant_import')); ?>"
                       class="button button-hero"><?php _e('Cancel', 'alttag-registrations'); ?></a>
                </p>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Display admin notices based on URL parameters (success, error, cancelled).
     */
    private function displayNotices(): void
    {
        if (isset($_GET['import_success'])) {
            $created = intval($_GET['created'] ?? 0);
            $updated = intval($_GET['updated'] ?? 0);
            $emails_sent = intval($_GET['emails_sent'] ?? 0);

            $message = __(
                'Import completed successfully. Created: %d, Updated: %d, Emails sent: %d',
                'alttag-registrations'
            );
            $this->renderNotice('success', sprintf($message, $created, $updated, $emails_sent));
        }

        if (isset($_GET['import_error'])) {
            $this->renderNotice('error', esc_html(urldecode($_GET['import_error'])));
        }

        if (isset($_GET['import_cancelled'])) {
            $this->renderNotice('info', __('Import cancelled.', 'alttag-registrations'));
        }
    }

    private function renderNotice(string $type, string $message): void
    {
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            $message
        );
    }

    /**
     * Handle the preview import form submission.
     *
     * Validates file, parses Excel data, categorizes participants,
     * and stores results in transients for the preview page.
     */
    public function handlePreviewImport(): void
    {
        $this->verifyNonce($_POST['import_nonce'] ?? '', 'preview_participant_import');
        $this->verifyCapability();

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $this->redirectWithError(__('File upload failed. Please try again.', 'alttag-registrations'));
            return;
        }

        $file = $_FILES['import_file'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($file_ext !== 'xlsx') {
            $this->redirectWithError(
                __('Invalid file type. Please upload an Excel file (.xlsx).', 'alttag-registrations')
            );
            return;
        }

        $parsed_data = $this->parseExcelFile($file['tmp_name']);

        if (is_wp_error($parsed_data)) {
            $this->redirectWithError($parsed_data->get_error_message());
            return;
        }

        $options = [
            'send_emails' => isset($_POST['send_emails']) && $_POST['send_emails'] === '1',
            'default_status' => sanitize_text_field($_POST['default_status'] ?? 'confirmed'),
            'default_language' => sanitize_text_field($_POST['default_language'] ?? 'sk'),
        ];

        $preview_data = $this->categorizeParticipants($parsed_data, $options);
        $preview_data['options'] = $options;

        $this->setPreviewTransient($preview_data);
        $this->setDataTransient(['participants' => $parsed_data, 'options' => $options]);

        wp_redirect($this->getImportPageUrl());
        exit;
    }

    /**
     * Handle the confirmed import execution.
     *
     * Creates new participants, updates existing ones, and optionally sends emails.
     */
    public function handleExecuteImport(): void
    {
        $this->verifyNonce($_POST['import_nonce'] ?? '', 'execute_participant_import');
        $this->verifyCapability();

        $import_data = $this->getDataTransient();
        $preview_data = $this->getPreviewTransient();

        if (!$import_data || !$preview_data) {
            $this->redirectWithError(
                __('Import session expired. Please upload the file again.', 'alttag-registrations')
            );
            return;
        }

        $result = $this->executeImport($preview_data, $import_data['options']);
        $this->clearImportTransients();

        $redirect_url = add_query_arg([
            'import_success' => 1,
            'created' => $result['created'],
            'updated' => $result['updated'],
            'emails_sent' => $result['emails_sent'],
        ], $this->getImportPageUrl());

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Parse an Excel file and extract participant data.
     *
     * @param string $file_path Path to the uploaded Excel file
     * @return array|\WP_Error Array of participant data or WP_Error on failure
     */
    private function parseExcelFile(string $file_path)
    {
        if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            $autoload_file = ALTTAG_REGISTRATIONS_PATH . '/vendor/autoload.php';
            if (file_exists($autoload_file)) {
                require_once $autoload_file;
            } else {
                return new \WP_Error(
                    'missing_library',
                    __('PhpSpreadsheet library is not available.', 'alttag-registrations')
                );
            }
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
            $data = $spreadsheet->getActiveSheet()->toArray();

            if (empty($data) || count($data) < 2) {
                return new \WP_Error(
                    'empty_file',
                    __('The file is empty or contains only headers.', 'alttag-registrations')
                );
            }

            $headers = array_map('trim', $data[0]);
            $header_map = $this->mapHeaders($headers);

            if (!isset($header_map['email'])) {
                return new \WP_Error(
                    'missing_email',
                    sprintf(
                        __('The file must contain an "Email" column. Found columns: %s', 'alttag-registrations'),
                        implode(', ', $headers)
                    )
                );
            }

            $participants = [];
            $row_count = count($data);

            for ($i = 1; $i < $row_count; $i++) {
                $participant = $this->mapRowToParticipant($data[$i], $header_map, $i + 1);
                if ($participant && !empty($participant['email'])) {
                    $participants[] = $participant;
                }
            }

            return $participants;
        } catch (\Exception $e) {
            return new \WP_Error(
                'parse_error',
                sprintf(__('Error parsing file: %s', 'alttag-registrations'), $e->getMessage())
            );
        }
    }

    /**
     * Map Excel column headers to participant field keys.
     *
     * Supports both meta field labels and common Slovak/English variations.
     *
     * @param array $headers Array of header strings from Excel
     * @return array Map of field_key => column_index
     */
    private function mapHeaders(array $headers): array
    {
        $metaFields = $this->manager->getMetaFields();
        $map = [];

        // Build lookup from labels to field keys
        $label_to_key = [];
        foreach ($metaFields as $key => $field) {
            $label = $field['admin_label'] ?? $field['label'];
            $label_to_key[strtolower($label)] = $key;
            $label_to_key[strtolower($key)] = $key;
        }

        $variations = $this->getHeaderVariations();

        foreach ($headers as $index => $header) {
            $header_lower = strtolower(trim($header));

            if (isset($label_to_key[$header_lower])) {
                $map[$label_to_key[$header_lower]] = $index;
            } elseif (isset($variations[$header_lower])) {
                $map[$variations[$header_lower]] = $index;
            }
        }

        return $map;
    }

    /**
     * Get common header name variations (Slovak translations, typos, etc.).
     *
     * @return array Map of variation => field_key
     */
    private function getHeaderVariations(): array
    {
        $variations = [
            'e-mail' => 'email',
            'e mail' => 'email',
            'meno' => 'first_name',
            'krstné meno' => 'first_name',
            'priezvisko' => 'last_name',
            'telefón' => 'phone',
            'telefon' => 'phone',
            'spoločnosť' => 'company_name',
            'firma' => 'company_name',
            'ulica' => 'street',
            'adresa' => 'street',
            'mesto' => 'city',
            'psč' => 'zip',
            'psc' => 'zip',
            'ičo' => 'business_id',
            'ico' => 'business_id',
            'dič' => 'tax_id',
            'dic' => 'tax_id',
            'ič dph' => 'vat_id',
            'ic dph' => 'vat_id',
            'jazyk' => 'language',
            'stav registrácie' => 'registration_status',
            'stav' => 'registration_status',
            'promo kód' => 'used_coupons',
            'kupón' => 'used_coupons',
            'kupon' => 'used_coupons',
            'poznámky' => 'order_comments',
            'poznamky' => 'order_comments',
            'variabilný symbol' => 'variable_symbol',
            'variabilny symbol' => 'variable_symbol',
            'vs' => 'variable_symbol',
            // Livestream variations
            'is livestream user' => 'is_livestream_user',
            'livestream user' => 'is_livestream_user',
            'livestream' => 'is_livestream_user',
            'online' => 'is_livestream_user',
        ];

        // Allow customizations to add header variations
        return apply_filters('alttag_registrations_import_header_variations', $variations);
    }

    /**
     * Map a single Excel row to participant data array.
     *
     * @param array $row Row data from Excel
     * @param array $header_map Field key => column index mapping
     * @param int $row_number Row number for error reporting
     * @return array Participant data with '_row' key for tracking
     */
    private function mapRowToParticipant(array $row, array $header_map, int $row_number): array
    {
        $participant = ['_row' => $row_number];

        foreach ($header_map as $field => $index) {
            if (!isset($row[$index])) {
                continue;
            }

            $value = trim($row[$index]);
            $participant[$field] = $this->normalizeFieldValue($field, $value);
        }

        return $participant;
    }

    /**
     * Normalize field values based on field type (status, language, country).
     */
    private function normalizeFieldValue(string $field, string $value): string
    {
        switch ($field) {
            case 'registration_status':
                return $this->normalizeStatus($value);
            case 'language':
                return strtolower($value);
            case 'country':
                return $this->normalizeCountry($value);
            case 'is_livestream_user':
                return $this->normalizeBoolean($value);
            default:
                // Allow customizations to normalize custom fields
                return apply_filters('alttag_registrations_import_normalize_field', $value, $field);
        }
    }

    /**
     * Normalize boolean values from Excel (Yes/No, Áno/Nie, 1/0, true/false).
     */
    private function normalizeBoolean(string $value): string
    {
        $true_values = ['1', 'yes', 'true', 'áno', 'ano'];
        // Use mb_strtolower for proper UTF-8 handling (e.g., 'Áno' → 'áno')
        return in_array(mb_strtolower(trim($value), 'UTF-8'), $true_values) ? '1' : '';
    }

    /**
     * Normalize registration status values (supports Slovak and English translations).
     */
    private function normalizeStatus(string $value): string
    {
        $status_map = [
            // English values (raw and translated)
            'confirmed' => 'confirmed',
            'pending' => 'pending',
            'cancelled' => 'cancelled',
            // Slovak translations
            'potvrdené' => 'confirmed',
            'potvrdeny' => 'confirmed',
            'potvrdená' => 'confirmed',
            'čakajúce' => 'pending',
            'cakajuce' => 'pending',
            'čakajúca' => 'pending',
            'zrušené' => 'cancelled',
            'zrusene' => 'cancelled',
            'zrušená' => 'cancelled',
        ];

        return $status_map[strtolower(trim($value))] ?? $value;
    }

    /**
     * Normalize country to ISO 2-letter code.
     *
     * Accepts country codes (SK, CZ) or full names (Slovensko, Czech Republic).
     */
    private function normalizeCountry(string $value): string
    {
        // Already a 2-letter code
        if (strlen($value) === 2) {
            return strtoupper($value);
        }

        // Try to find country code by name
        $countries = \Alttag\Registrations\get_woocommerce_countries();
        $value_lower = strtolower($value);

        foreach ($countries as $code => $name) {
            if (strtolower($name) === $value_lower) {
                return $code;
            }
        }

        return $value;
    }

    /**
     * Categorize parsed participants into new, update, skipped, or error groups.
     *
     * @param array $participants Parsed participant data
     * @param array $options Import options (default_status, default_language, etc.)
     * @return array Contains 'new', 'update', 'skipped', and 'errors' arrays
     */
    private function categorizeParticipants(array $participants, array $options): array
    {
        $new = [];
        $update = [];
        $skipped = [];
        $errors = [];

        foreach ($participants as $participant) {
            $row = $participant['_row'];
            unset($participant['_row']);

            $email = $participant['email'] ?? '';

            $validation_error = $this->validateParticipantEmail($email, $row);
            if ($validation_error) {
                $errors[] = $validation_error;
                continue;
            }

            // If import data includes livestream type, match by email + type
            // so the same email can have separate livestream and in-person registrations
            if (isset($participant['is_livestream_user'])) {
                $existing = $this->manager->getParticipantByEmailAndType($email, $participant['is_livestream_user']);
            } else {
                $existing = $this->manager->getParticipantByEmail($email);
            }

            if (!empty($existing)) {
                $existing_details = $this->manager->getParticipantDetails($existing->ID);
                $changes = $this->detectChanges($existing_details, $participant);

                if (!empty($changes)) {
                    $update[] = [
                        'id' => $existing->ID,
                        'email' => $email,
                        'current_name' => $existing_details['first_name'] . ' ' . $existing_details['last_name'],
                        'changes' => $changes,
                        'data' => $participant,
                    ];
                } else {
                    // Participant exists but has no changes
                    $skipped[] = [
                        'email' => $email,
                        'name' => $existing_details['first_name'] . ' ' . $existing_details['last_name'],
                        'reason' => __('No changes detected', 'alttag-registrations'),
                    ];
                }
            } else {
                $new[] = $participant;
            }
        }

        return compact('new', 'update', 'skipped', 'errors');
    }

    /**
     * Validate participant email and return error array if invalid.
     *
     * @return array|null Error array with 'row', 'email', 'message' or null if valid
     */
    private function validateParticipantEmail(string $email, int $row): ?array
    {
        if (empty($email)) {
            return [
                'row' => $row,
                'email' => '',
                'message' => __('Missing email address', 'alttag-registrations'),
            ];
        }

        if (!is_email($email)) {
            return [
                'row' => $row,
                'email' => $email,
                'message' => __('Invalid email address', 'alttag-registrations'),
            ];
        }

        return null;
    }

    /**
     * Detect which fields have changed between existing and imported data.
     *
     * Note: variable_symbol is NOT updatable - it's generated once and kept.
     *
     * @param array $existing Current participant data from database
     * @param array $new Imported participant data
     * @return array Changes with field labels as keys, containing 'field', 'old', 'new'
     */
    private function detectChanges(array $existing, array $new): array
    {
        // Note: variable_symbol is intentionally excluded - we don't update it
        $updatable_fields = [
            'first_name', 'last_name', 'phone', 'company_name',
            'street', 'city', 'zip', 'country', 'business_id',
            'tax_id', 'vat_id', 'language', 'registration_status',
            'used_coupons', 'order_comments', 'is_livestream_user'
        ];

        $metaFields = $this->manager->getMetaFields();
        $changes = [];

        foreach ($updatable_fields as $field) {
            if (!isset($new[$field]) || $new[$field] === '') {
                continue;
            }

            $old_value = $existing[$field] ?? '';
            $new_value = $new[$field];

            // Normalize values for comparison to handle export format differences
            $old_normalized = $this->normalizeForComparison($field, $old_value);
            $new_normalized = $this->normalizeForComparison($field, $new_value);

            if ($old_normalized !== $new_normalized) {
                $label = $metaFields[$field]['label'] ?? $field;
                $changes[$label] = [
                    'field' => $field,
                    'old' => $old_value,
                    'new' => $new_normalized, // Store normalized value for update
                ];
            }
        }

        return $changes;
    }

    /**
     * Normalize field value for comparison (handles export format differences).
     *
     * Export converts codes to labels (SK -> Slovakia, confirmed -> Confirmed),
     * this converts them back for accurate comparison.
     */
    private function normalizeForComparison(string $field, string $value): string
    {
        if ($value === '') {
            return '';
        }

        switch ($field) {
            case 'country':
                return $this->normalizeCountry($value);
            case 'registration_status':
                return $this->normalizeStatus($value);
            case 'language':
                return strtolower($value);
            default:
                return (string) $value;
        }
    }

    /**
     * Execute the actual import - create new participants and update existing ones.
     *
     * @param array $preview_data Categorized data from preview step
     * @param array $options Import options (send_emails, default_status, etc.)
     * @return array Counts of 'created', 'updated', 'emails_sent'
     */
    private function executeImport(array $preview_data, array $options): array
    {
        $created = 0;
        $updated = 0;
        $emails_sent = 0;

        $verificationManager = apply_filters('alttag_registrations_verification_manager', null);

        foreach ($preview_data['new'] as $participant_data) {
            $result = $this->createNewParticipant($participant_data, $options, $verificationManager);
            if ($result['success']) {
                $created++;
                $emails_sent += $result['email_sent'] ? 1 : 0;
            }
        }

        foreach ($preview_data['update'] as $update_info) {
            if ($this->updateExistingParticipant($update_info)) {
                $updated++;
            }
        }

        return compact('created', 'updated', 'emails_sent');
    }

    /**
     * Create a new participant from imported data.
     *
     * Generates variable symbol, QR code, ticket, and optionally sends email.
     *
     * @return array Contains 'success' and 'email_sent' boolean flags
     */
    private function createNewParticipant(array $data, array $options, $verificationManager): array
    {
        // Remove fields that should not be imported for new participants
        // These will be generated fresh or set from settings
        $fields_to_skip = [
            'variable_symbol',      // Will be generated new
            'order_id',             // Skip - no order for imported participants
            'invoice_id',           // Skip - no invoice for imported participants
            'invoice_url',          // Skip - no invoice for imported participants
            'qr_code_url',          // Will be generated new
            'ticket_file_path',     // Will be generated new
            'livestream_access',    // Will be set by webhook
            'create_date',          // Will be set to current time by Manager
            'update_date',          // Will be set to current time by Manager
            'payment_method',       // Skip - no payment for imported participants
        ];
        foreach ($fields_to_skip as $field) {
            unset($data[$field]);
        }

        // Always use status from settings, ignore Excel value
        $data['registration_status'] = $options['default_status'];

        // Use language from Excel if present, otherwise from settings
        if (empty($data['language'])) {
            $data['language'] = $options['default_language'];
        }

        $data['variable_symbol'] = $this->generateVariableSymbol();
        $participant_id = $this->manager->createParticipant($data);

        if (!$participant_id) {
            return ['success' => false, 'email_sent' => false];
        }

        if ($verificationManager) {
            $qr_code_url = $verificationManager->generateQRCodeUrl($data['variable_symbol']);
            update_post_meta($participant_id, 'qr_code_url', $qr_code_url);
            $verificationManager->generateAndSaveTicket($participant_id);
        }

        update_post_meta($participant_id, 'registration_status', $data['registration_status']);
        update_post_meta($participant_id, 'imported_at', current_time('mysql'));
        $this->addCreationHistory($participant_id, $data);

        $email_sent = false;
        if ($options['send_emails']) {
            $email_sent = $this->sendImportEmail($participant_id, $data);
            if ($email_sent) {
                $this->appendHistory(
                    $participant_id,
                    __('Confirmation email with ticket sent', 'alttag-registrations')
                );
            }
        }

        return ['success' => true, 'email_sent' => $email_sent];
    }

    /**
     * Update an existing participant with changed fields.
     *
     * @param array $update_info Contains 'id', 'changes' array
     * @return bool True if update was performed
     */
    private function updateExistingParticipant(array $update_info): bool
    {
        $update_data = [];
        foreach ($update_info['changes'] as $change) {
            $update_data[$change['field']] = $change['new'];
        }

        if (empty($update_data)) {
            return false;
        }

        $this->manager->updateParticipant($update_info['id'], $update_data);
        $this->addUpdateHistory($update_info['id'], $update_info['changes']);

        return true;
    }

    /**
     * Add creation history entry for a newly imported participant.
     */
    private function addCreationHistory(int $participant_id, array $data): void
    {
        $current_user = wp_get_current_user();

        $details = [
            sprintf(__('Status: %s', 'alttag-registrations'), $data['registration_status']),
            sprintf(__('Language: %s', 'alttag-registrations'), strtoupper($data['language'])),
        ];

        if (!empty($data['used_coupons'])) {
            $details[] = sprintf(__('Promo code: %s', 'alttag-registrations'), $data['used_coupons']);
        }

        $details[] = sprintf(__('Variable symbol: %s', 'alttag-registrations'), $data['variable_symbol']);
        $details[] = sprintf(__('Imported by: %s', 'alttag-registrations'), $current_user->display_name);

        $history = current_time('mysql') . ': ' . __('CREATED VIA IMPORT', 'alttag-registrations');
        $history .= "\n   - " . implode("\n   - ", $details);

        update_post_meta($participant_id, 'registration_history', $history);
    }

    /**
     * Add update history entry listing all changed fields.
     */
    private function addUpdateHistory(int $participant_id, array $changes): void
    {
        $current_user = wp_get_current_user();
        $empty_label = __('(empty)', 'alttag-registrations');

        $change_details = [];
        foreach ($changes as $label => $change) {
            $old_display = $change['old'] !== '' ? $change['old'] : $empty_label;
            $change_details[] = sprintf('%s: %s → %s', $label, $old_display, $change['new']);
        }

        $entry = __('UPDATED VIA IMPORT', 'alttag-registrations');
        $entry .= "\n   - " . sprintf(__('Updated by: %s', 'alttag-registrations'), $current_user->display_name);
        $entry .= "\n   - " . __('Changes:', 'alttag-registrations');
        $entry .= "\n     - " . implode("\n     - ", $change_details);

        $this->appendHistory($participant_id, $entry);
    }

    /**
     * Append a timestamped entry to participant's registration history.
     */
    private function appendHistory(int $participant_id, string $entry): void
    {
        $state = \Alttag\Registrations\ParticipantState::get($participant_id);
        if ($state) {
            $state->addToHistory($entry);
        }
    }

    /**
     * Generate unique variable symbol for imported participants.
     *
     * Uses same format as manual participants: M + Year + timestamp + random.
     */
    private function generateVariableSymbol(): string
    {
        global $wpdb;

        do {
            $year = date('Y');
            $timestamp = time();
            $symbol = 'M' . $year . substr($timestamp, -6);
            $symbol .= str_pad(rand(1, 99), 2, '0', STR_PAD_LEFT);

            // Check if symbol already exists
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE pm.meta_key = 'variable_symbol'
                 AND pm.meta_value = %s
                 AND p.post_type = 'participant'
                 AND p.post_status != 'trash'",
                $symbol
            ));
        } while ($existing);

        return $symbol;
    }

    private function redirectWithError(string $message): void
    {
        wp_redirect(add_query_arg(['import_error' => urlencode($message)], $this->getImportPageUrl()));
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Transient Helper Methods
    |--------------------------------------------------------------------------
    |
    | These methods manage temporary storage for import sessions.
    | Each user gets their own transients to prevent conflicts.
    |
    */

    /**
     * Get user-specific transient key.
     */
    private function getTransientKey(string $suffix): string
    {
        return self::TRANSIENT_PREFIX . $suffix . '_' . get_current_user_id();
    }

    private function getPreviewTransient()
    {
        return get_transient($this->getTransientKey('preview'));
    }

    private function setPreviewTransient(array $data): void
    {
        set_transient($this->getTransientKey('preview'), $data, self::TRANSIENT_EXPIRY);
    }

    private function getDataTransient()
    {
        return get_transient($this->getTransientKey('data'));
    }

    private function setDataTransient(array $data): void
    {
        set_transient($this->getTransientKey('data'), $data, self::TRANSIENT_EXPIRY);
    }

    private function clearImportTransients(): void
    {
        delete_transient($this->getTransientKey('preview'));
        delete_transient($this->getTransientKey('data'));
    }

    /*
    |--------------------------------------------------------------------------
    | Security Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Verify nonce or die with error message.
     */
    private function verifyNonce(string $nonce, string $action): void
    {
        if (!wp_verify_nonce($nonce, $action)) {
            wp_die(__('Security check failed', 'alttag-registrations'));
        }
    }

    /**
     * Verify user has manage_options capability or die.
     */
    private function verifyCapability(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to import participants', 'alttag-registrations'));
        }
    }

    /**
     * Get the import admin page URL.
     */
    private function getImportPageUrl(): string
    {
        return admin_url('edit.php?post_type=participant&page=alttag-import');
    }

    /**
     * Send confirmation email with ticket for imported participant.
     *
     * Uses the same email template as regular order emails for consistency.
     *
     * @param int $participant_id Participant ID
     * @param array $data Participant data
     * @return bool True if email was sent successfully
     */
    private function sendImportEmail(int $participant_id, array $data): bool
    {
        $participant = $this->manager->getParticipantDetails($participant_id);
        if (!$participant || empty($participant['email'])) {
            return false;
        }

        // Get settings instance for email template
        $settings = new \Alttag\Registrations\Settings();

        // Prepare email data - same structure as WooCommerceManager::sendCustomEmail
        // but without WooCommerce order object
        $email_data = [
            'order' => null, // No order for imported participants
            'order_id' => null,
            'order_status' => 'completed', // Treat as completed for imported participants
            'first_name' => $participant['first_name'] ?? '',
            'last_name' => $participant['last_name'] ?? '',
            'email' => $participant['email'] ?? '',
            'phone' => $participant['phone'] ?? '',
            'company_name' => $participant['company_name'] ?? '',
            'street' => $participant['street'] ?? '',
            'city' => $participant['city'] ?? '',
            'zip' => $participant['zip'] ?? '',
            'country' => $participant['country'] ?? '',
            'country_name' => $participant['country_name'] ?? '',
            'business_id' => $participant['business_id'] ?? '',
            'tax_id' => $participant['tax_id'] ?? '',
            'vat_id' => $participant['vat_id'] ?? '',
            'variable_symbol' => $participant['variable_symbol'] ?? '',
            'qr_code_url' => $participant['qr_code_url'] ?? '',
            'ticket_url' => $participant['ticket_url'] ?? '',
            'ticket_full_file_path' => $participant['ticket_full_file_path'] ?? '',
            'invoice_url' => '', // No invoice for imported participants
            'payment_method' => '', // No payment method for imported participants
            'order_comments' => $participant['order_comments'] ?? '',
            'participant_id' => $participant_id,
            'is_livestream_user' => filter_var($participant['is_livestream_user'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'is_imported' => true, // Flag to indicate this is an imported participant (no payment)
        ];

        // Allow language switching before generating email content
        do_action('alttag_registrations_before_order_email', null);

        // Get email template
        $template = $settings->getEmailTemplate(null);

        // Generate subject based on whether participant is livestream user
        // For livestream users: "Registration confirmation for X"
        // For regular participants: "Your ticket for X" (or customized via filter)
        $event_name = \Alttag\Registrations\get_event_name_with_year();
        if ($email_data['is_livestream_user']) {
            $email_data['title'] = apply_filters(
                'alttag_registrations_import_email_subject',
                sprintf(__('Registration confirmation for %s', 'alttag-registrations'), $event_name),
                $participant_id,
                $email_data
            );
        } else {
            $email_data['title'] = apply_filters(
                'alttag_registrations_import_email_subject',
                sprintf(__('Your ticket for %s', 'alttag-registrations'), $event_name),
                $participant_id,
                $email_data
            );
        }

        $parser = new \Alttag\Registrations\TemplateParser($template, $email_data);
        $email_content = $parser->render();

        // Convert content to table-based layout for better email client compatibility
        $email_content = \Alttag\Registrations\EmailWrapper::convertToTableLayout($email_content);

        // Wrap content in Outlook-compatible HTML structure
        $preheader = wp_strip_all_tags(substr($email_data['title'], 0, 100));
        $email_content = \Alttag\Registrations\EmailWrapper::wrap($email_content, $preheader);

        // Prepare attachments (ticket only for non-livestream users, no invoice)
        $include_ticket = !$email_data['is_livestream_user'];
        $attachments = Manager::prepareEmailAttachments($participant, $include_ticket, false);

        // Send email
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $sent = wp_mail($participant['email'], $email_data['title'], $email_content, $headers, $attachments);

        // Cleanup temporary files
        foreach ($attachments as $attachment) {
            if (strpos($attachment, sys_get_temp_dir()) !== false && file_exists($attachment)) {
                @unlink($attachment);
            }
        }

        // Allow language restoration after sending email
        do_action('alttag_registrations_after_order_email', null);

        return $sent;
    }
}
