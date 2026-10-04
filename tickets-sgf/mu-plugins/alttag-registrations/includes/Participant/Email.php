<?php

namespace Alttag\Registrations\Participant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles email functionality for participants
 */
class Email
{
    private $manager;

    public function __construct(Manager $manager)
    {
        $enable = apply_filters('alttag_registrations_enable_participants', true);

        if (!$enable) {
            return;
        }

        $this->manager = $manager;

        // Add email functionality
        add_action('admin_menu', [$this, 'addEmailMenuPage']);
        add_action('admin_post_send_participant_email', [$this, 'handleSendParticipantEmail']);
        add_action('admin_post_send_test_participant_email', [$this, 'handleSendTestParticipantEmail']);
        add_action('admin_post_resend_email_with_ticket_and_invoice', [$this, 'handleResendEmailWithTicketAndInvoice']);
    }

    /**
     * Add email menu page
     *
     * @return void
     */
    public function addEmailMenuPage()
    {
        // Add a hidden submenu page for sending emails
        add_submenu_page(
            null, // No parent menu - this makes it hidden
            __('Send Email', 'alttag-registrations'),
            __('Send Email', 'alttag-registrations'),
            'manage_options',
            'alttag-email',
            [$this, 'renderEmailPage']
        );

        add_submenu_page(
            'edit.php?post_type=participant',
            __('Email Templates', 'alttag-registrations'),
            __('Email Templates', 'alttag-registrations'),
            'manage_options',
            'alttag-email-templates',
            [$this, 'renderEmailTemplatesPage']
        );
    }

    /**
     * Render email templates page
     *
     * @return void
     */
    public function renderEmailTemplatesPage()
    {
        // Handle template saving
        if (isset($_POST['save_template']) && isset($_POST['template_nonce']) &&
            wp_verify_nonce($_POST['template_nonce'], 'save_email_template')) {
            $template_name = sanitize_text_field($_POST['template_name']);
            $original_name = isset($_POST['original_template_name']) ?
                sanitize_text_field($_POST['original_template_name']) : '';
            $email_subject = sanitize_text_field(wp_unslash($_POST['email_subject']));
            // wp_kses_post preserves all safe HTML tags including <p>, <br>, <strong>, etc.
            // wp_unslash strips WordPress-added slashes so repeat saves don't accumulate them.
            $email_content = wp_kses_post(wp_unslash($_POST['email_content']));

            if (!empty($template_name) && !empty($email_subject) && !empty($email_content)) {
                $templates = get_option('alttag_email_templates', []);

                // If editing and name changed, delete old template
                if (!empty($original_name) && $original_name !== $template_name &&
                    isset($templates[$original_name])) {
                    unset($templates[$original_name]);
                }

                $templates[$template_name] = [
                    'subject' => $email_subject,
                    'content' => $email_content,
                    'created' => isset($templates[$template_name]['created']) ?
                        $templates[$template_name]['created'] : current_time('mysql'),
                ];
                update_option('alttag_email_templates', $templates);

                echo '<div class="notice notice-success is-dismissible"><p>' .
                    sprintf(__('Template "%s" saved successfully.', 'alttag-registrations'), esc_html($template_name)) .
                    '</p></div>';
            }
        }

        // Handle template deletion
        if (isset($_GET['delete_template']) && isset($_GET['_wpnonce'])) {
            $template_name = sanitize_text_field($_GET['delete_template']);
            if (wp_verify_nonce($_GET['_wpnonce'], 'delete_template_' . $template_name)) {
                $templates = get_option('alttag_email_templates', []);
                if (isset($templates[$template_name])) {
                    unset($templates[$template_name]);
                    update_option('alttag_email_templates', $templates);

                    echo '<div class="notice notice-success is-dismissible"><p>' .
                        sprintf(__('Template "%s" deleted successfully.', 'alttag-registrations'), esc_html($template_name)) .
                        '</p></div>';
                }
            }
        }

        // Get existing templates
        $templates = get_option('alttag_email_templates', []);

        // Check if editing a template
        $editing_template = isset($_GET['edit_template']) ? sanitize_text_field($_GET['edit_template']) : '';
        $edit_template_data = null;
        if (!empty($editing_template) && isset($templates[$editing_template])) {
            $edit_template_data = $templates[$editing_template];
            $edit_template_data['name'] = $editing_template;
        }

        // Get meta fields for placeholders
        $metaFields = $this->manager->getMetaFields();
        $placeholders = [];
        
        // Add all meta fields as placeholders
        foreach ($metaFields as $key => $field) {
            $placeholders[] = '{' . $key . '}';
        }
        
        // Add some special placeholders
        $placeholders[] = '{ticket_link}';
        $placeholders[] = '{invoice_link}';
        $placeholders[] = '{event_name}';
        $placeholders[] = '{event_date}';
        $placeholders[] = '{event_location}';
        
        // Sort placeholders alphabetically
        sort($placeholders);

        ?>
        <div class="wrap">
            <h1><?php _e('Email Templates', 'alttag-registrations'); ?></h1>
            
            <div class="postbox">
                <div class="inside">
                    <h2>
                        <?php
                        if ($edit_template_data) {
                            _e('Edit Template', 'alttag-registrations');
                        } else {
                            _e('Create New Template', 'alttag-registrations');
                        }
                        ?>
                    </h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('save_email_template', 'template_nonce'); ?>
                        <?php if ($edit_template_data) : ?>
                            <input type="hidden" name="original_template_name"
                                   value="<?php echo esc_attr($edit_template_data['name']); ?>">
                        <?php endif; ?>

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="template_name">
                                        <?php _e('Template Name', 'alttag-registrations'); ?>
                                    </label>
                                </th>
                                <td>
                                    <input type="text" name="template_name" id="template_name"
                                           class="regular-text" required value="<?php
                                            echo $edit_template_data ? esc_attr($edit_template_data['name']) : '';
                                            ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="email_subject">
                                        <?php _e('Email Subject', 'alttag-registrations'); ?>
                                    </label>
                                </th>
                                <td>
                                    <input type="text" name="email_subject" id="email_subject"
                                           class="regular-text" required value="<?php
                                            echo $edit_template_data ? esc_attr($edit_template_data['subject']) : '';
                                            ?>">
                                    <p class="description">
                                        <?php _e('You can use placeholders like {first_name}, {last_name}, etc.', 'alttag-registrations'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="email_content">
                                        <?php _e('Email Content', 'alttag-registrations'); ?>
                                    </label>
                                </th>
                                <td>
                                    <?php
                                    wp_editor(
                                        $edit_template_data ? $edit_template_data['content'] : '',
                                        'email_content',
                                        [
                                            'textarea_name' => 'email_content',
                                            'textarea_rows' => 15,
                                            'media_buttons' => true,
                                        ]
                                    );
                                    ?>
                                    <p class="description">
                                        <?php _e('Available placeholders:', 'alttag-registrations'); ?>
                                        <br>
                                        <div class="placeholder-list" style="max-height: 150px; overflow-y: auto; padding: 10px; background: #f8f8f8; border: 1px solid #ddd; margin-top: 5px;">
                                            <?php foreach ($placeholders as $placeholder) : ?>
                                                <code><?php echo esc_html($placeholder); ?></code>
                                                <?php if (next($placeholders)) echo ', '; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </p>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <input type="submit" name="save_template" class="button button-primary"
                                   value="<?php _e('Save Template', 'alttag-registrations'); ?>">
                            <?php if ($edit_template_data) : ?>
                                <a href="<?php
                                echo admin_url('edit.php?post_type=participant&page=alttag-email-templates');
                                ?>" class="button">
                                    <?php _e('Cancel', 'alttag-registrations'); ?>
                                </a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>
            </div>
            
            <?php if (!empty($templates)) : ?>
                <h2><?php _e('Saved Templates', 'alttag-registrations'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Template Name', 'alttag-registrations'); ?></th>
                            <th><?php _e('Subject', 'alttag-registrations'); ?></th>
                            <th><?php _e('Created', 'alttag-registrations'); ?></th>
                            <th><?php _e('Actions', 'alttag-registrations'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($templates as $name => $template) : ?>
                            <tr>
                                <td><?php echo esc_html($name); ?></td>
                                <td><?php echo esc_html($template['subject']); ?></td>
                                <td><?php echo isset($template['created']) ? date_i18n(get_option('date_format'), strtotime($template['created'])) : '-'; ?></td>
                                <td>
                                    <a href="#" class="button view-template"
                                       data-name="<?php echo esc_attr($name); ?>">
                                        <?php _e('View', 'alttag-registrations'); ?>
                                    </a>
                                    <a href="<?php echo add_query_arg('edit_template', urlencode($name)); ?>"
                                       class="button">
                                        <?php _e('Edit', 'alttag-registrations'); ?>
                                    </a>
                                    <a href="<?php
                                             echo wp_nonce_url(
                                                 add_query_arg('delete_template', urlencode($name)),
                                                 'delete_template_' . $name
                                             );
                                                ?>" class="button"
                                       onclick="return confirm('<?php
                                                                esc_attr_e(
                                                                    'Are you sure you want to delete this template?',
                                                                    'alttag-registrations'
                                                                );
                                                                ?>');">
                                        <?php _e('Delete', 'alttag-registrations'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <style>
                    #template-viewer-content {
                        background: #fff;
                        padding: 15px;
                        border: 1px solid #ddd;
                        min-height: 100px;
                    }
                    #template-viewer-content p {
                        margin: 1em 0;
                    }
                    #template-viewer-content br {
                        display: block;
                        margin: 0.5em 0;
                        content: "";
                    }
                </style>

                <div id="template-viewer" style="display: none;" class="postbox">
                    <div class="inside">
                        <h2 id="template-viewer-title"></h2>
                        <h3><?php _e('Subject', 'alttag-registrations'); ?></h3>
                        <div id="template-viewer-subject"></div>
                        <h3><?php _e('Content', 'alttag-registrations'); ?></h3>
                        <div id="template-viewer-content"></div>
                        <p>
                            <button class="button close-viewer"><?php _e('Close', 'alttag-registrations'); ?></button>
                        </p>
                    </div>
                </div>
                
                <script>
                jQuery(document).ready(function($) {
                    // Template data
                    var templates = <?php echo json_encode($templates); ?>;

                    // View template
                    $('.view-template').click(function(e) {
                        e.preventDefault();
                        var name = $(this).data('name');
                        var template = templates[name];

                        $('#template-viewer-title').text(name);
                        $('#template-viewer-subject').text(template.subject);

                        // Convert line breaks to HTML and display properly
                        var content = template.content;
                        // If content doesn't have HTML tags, convert line breaks
                        if (content.indexOf('<p>') === -1 && content.indexOf('<br') === -1) {
                            // Replace double line breaks with paragraph breaks
                            // First, normalize line breaks
                            content = content.replace(/\r\n/g, '\n');
                            // Split into paragraphs (separated by empty lines)
                            var paragraphs = content.split(/\n\n+/);
                            var html = [];
                            for (var i = 0; i < paragraphs.length; i++) {
                                var para = paragraphs[i].trim();
                                if (para !== '') {
                                    // Replace single line breaks within paragraph with <br>
                                    para = para.replace(/\n/g, '<br>');
                                    html.push('<p>' + para + '</p>');
                                }
                            }
                            content = html.join('');
                        }
                        $('#template-viewer-content').html(content);
                        $('#template-viewer').show();
                    });

                    // Close viewer
                    $('.close-viewer').click(function() {
                        $('#template-viewer').hide();
                    });
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render email page
     *
     * @return void
     */
    public function renderEmailPage()
    {
        // Get selected participant IDs from transient
        $participant_ids = get_transient('alttag_email_participants');

        if (empty($participant_ids)) {
            wp_die(__('No participants selected or selection expired. Please select participants again.', 'alttag-registrations'));
        }

        // Get participant details
        $participants = [];
        foreach ($participant_ids as $id) {
            $participant = $this->manager->getParticipantDetails($id);
            if ($participant) {
                $participants[] = $participant;
            }
        }

        if (empty($participants)) {
            wp_die(__('No valid participants found.', 'alttag-registrations'));
        }

        $metaFields = $this->manager->getMetaFields();
        $placeholders = [];

        // Add all meta fields as placeholders
        foreach ($metaFields as $key => $field) {
            $placeholders[] = '{' . $key . '}';
        }

        // Add some special placeholders
        $placeholders[] = '{ticket_link}';
        $placeholders[] = '{invoice_link}';
        $placeholders[] = '{event_name}';
        $placeholders[] = '{event_date}';
        $placeholders[] = '{event_location}';

        // Sort placeholders alphabetically
        sort($placeholders);

        // Get email templates
        $templates = get_option('alttag_email_templates', []);

        // Generate a unique session ID for this email operation
        $email_session_id = md5(current_time('timestamp') . wp_rand(0, 1000) . get_current_user_id());
        set_transient('alttag_email_session_' . $email_session_id, $participant_ids, 60 * 15); // 15 minutes expiration

        // Get preserved form values from transient (if returning from test email)
        $preserved_values = get_transient('alttag_email_form_values_' . get_current_user_id());
        $saved_subject = isset($preserved_values['subject']) ? $preserved_values['subject'] : '';
        $saved_content = isset($preserved_values['content']) ? $preserved_values['content'] : '';
        $saved_test_email = isset($preserved_values['test_email']) ?
            $preserved_values['test_email'] : wp_get_current_user()->user_email;
        $saved_include_ticket = isset($preserved_values['include_ticket']) ?
            $preserved_values['include_ticket'] : false;
        $saved_include_invoice = isset($preserved_values['include_invoice']) ?
            $preserved_values['include_invoice'] : false;
        $saved_template_name = isset($preserved_values['template_name']) ?
            $preserved_values['template_name'] : '';

        // Delete the transient after reading it (one-time use)
        delete_transient('alttag_email_form_values_' . get_current_user_id());

        ?>
        <div class="wrap">
            <h1><?php _e('Send Email to Participants', 'alttag-registrations'); ?></h1>

            <?php
            // Display test email notice
            if (isset($_GET['test_email_sent'])) {
                if ($_GET['test_email_sent'] === '1') {
                    $test_email_to = isset($_GET['test_email_to']) ? urldecode($_GET['test_email_to']) : '';
                    echo '<div class="notice notice-success is-dismissible"><p>';
                    printf(
                        __('Test email sent successfully to %s', 'alttag-registrations'),
                        '<strong>' . esc_html($test_email_to) . '</strong>'
                    );
                    echo '</p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>';
                    _e('Failed to send test email. Please check your email configuration.', 'alttag-registrations');
                    echo '</p></div>';
                }
            }
            ?>

            <div class="notice notice-info">
                <p>
                    <?php
                    printf(
                        _n(
                            'You are about to send an email to %s participant.',
                            'You are about to send an email to %s participants.',
                            count($participants),
                            'alttag-registrations'
                        ),
                        '<strong>' . count($participants) . '</strong>'
                    );
        ?>
                </p>
                
                <?php
                // Get emails for this status
                $emails = [];
        foreach ($participants as $participant) {
            $emails[] = $participant['email'];
        }

        if (!empty($emails)) {
            echo '<p>';
            echo '<strong>' . __('Emails', 'alttag-registrations') . ':</strong><br>';
            echo esc_html(implode(', ', $emails));
            echo '</p>';
        }
        ?>
            </div>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="send_participant_email">
                <input type="hidden" name="email_session_id" value="<?php echo esc_attr($email_session_id); ?>">
                <?php wp_nonce_field('send_participant_email', 'email_nonce'); ?>
                
                <?php if (!empty($templates)) : ?>
                    <div class="postbox">
                        <div class="inside">
                            <h3><?php _e('Use Template', 'alttag-registrations'); ?></h3>
                            <select id="email_template" name="email_template">
                                <option value=""><?php _e('-- Select Template --', 'alttag-registrations'); ?></option>
                                <?php foreach ($templates as $name => $template) : ?>
                                    <option value="<?php echo esc_attr($name); ?>"
                                        <?php selected($saved_template_name, $name); ?>>
                                        <?php echo esc_html($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="load_template" class="button"><?php _e('Load Template', 'alttag-registrations'); ?></button>
                        </div>
                    </div>
                    
                    <script>
                    jQuery(document).ready(function($) {
                        // Template data
                        var templates = <?php echo json_encode($templates); ?>;
                        var currentTemplateName = '<?php echo esc_js($saved_template_name); ?>';

                        // Load template
                        $('#load_template').click(function() {
                            var templateName = $('#email_template').val();
                            if (templateName && templates[templateName]) {
                                var template = templates[templateName];
                                $('#email_subject').val(template.subject);

                                // Store current template name
                                currentTemplateName = templateName;

                                // Convert line breaks to HTML if content doesn't have HTML
                                var content = template.content;
                                if (content.indexOf('<p>') === -1 && content.indexOf('<br') === -1) {
                                    // Replace double line breaks with paragraph breaks
                                    // First, normalize line breaks
                                    content = content.replace(/\r\n/g, '\n');
                                    // Split into paragraphs (separated by empty lines)
                                    var paragraphs = content.split(/\n\n+/);
                                    var html = [];
                                    for (var i = 0; i < paragraphs.length; i++) {
                                        var para = paragraphs[i].trim();
                                        if (para !== '') {
                                            // Replace single line breaks within paragraph with <br>
                                            para = para.replace(/\n/g, '<br>');
                                            html.push('<p>' + para + '</p>');
                                        }
                                    }
                                    content = html.join('');
                                }

                                // Handle different editor types
                                if (typeof tinyMCE !== 'undefined' && tinyMCE.get('email_content')) {
                                    tinyMCE.get('email_content').setContent(content);
                                } else {
                                    $('#email_content').val(content);
                                }
                            }
                        });

                        // When save as template is checked, prefill with current template name
                        $('#save_as_template').change(function() {
                            if ($(this).is(':checked')) {
                                $('#new_template_name').show();
                                if (currentTemplateName) {
                                    $('#new_template_name').val(currentTemplateName);
                                }
                            } else {
                                $('#new_template_name').hide();
                            }
                        });
                    });
                    </script>
                <?php endif; ?>
                
                <div class="postbox">
                    <div class="inside">
                        <h3><?php _e('Email Content', 'alttag-registrations'); ?></h3>
                        <table class="form-table">
                            <tr>
                                <td>
                                    <?php
                                    wp_editor(
                                        $saved_content,
                                        'email_content',
                                        [
                                            'textarea_name' => 'email_content',
                                            'textarea_rows' => 15,
                                            'media_buttons' => true,
                                        ]
                                    );
                                    ?>
                                    <p class="description">
                                        <?php _e('You can use the following placeholders in your email:', 'alttag-registrations'); ?>
                                        <br>
                                        <div class="placeholder-list" style="max-height: 150px; overflow-y: auto; padding: 10px; background: #f8f8f8; border: 1px solid #ddd; margin-top: 5px;">
                                            <?php foreach ($placeholders as $placeholder) : ?>
                                                <code><?php echo esc_html($placeholder); ?></code>
                                                <?php if (next($placeholders)) echo ', '; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="email_subject"><?php _e('Email Subject', 'alttag-registrations'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="email_subject" id="email_subject" class="regular-text"
                                   required value="<?php echo esc_attr($saved_subject); ?>">
                            <p class="description">
                                <?php _e('You can use the same placeholders as in the email content.', 'alttag-registrations'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Attachments', 'alttag-registrations'); ?></th>
                        <td>
                            <fieldset>
                                <label for="include_ticket">
                                    <input type="checkbox" name="include_ticket" id="include_ticket" value="1"
                                           <?php checked($saved_include_ticket, true); ?>>
                                    <?php _e('Attach ticket PDF to the email', 'alttag-registrations'); ?>
                                </label>
                                <br>
                                <label for="include_invoice">
                                    <input type="checkbox" name="include_invoice" id="include_invoice" value="1"
                                           <?php checked($saved_include_invoice, true); ?>>
                                    <?php _e('Attach invoice PDF to the email', 'alttag-registrations'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="save_as_template"><?php _e('Save as Template', 'alttag-registrations'); ?></label></th>
                        <td>
                            <input type="checkbox" name="save_as_template" id="save_as_template" value="1">
                            <label for="save_as_template"><?php _e('Save this email as a template for future use', 'alttag-registrations'); ?></label>
                            <br>
                            <input type="text" name="new_template_name" id="new_template_name" class="regular-text" placeholder="<?php esc_attr_e('Template Name', 'alttag-registrations'); ?>" style="margin-top: 5px; display: none;">
                        </td>
                    </tr>
                </table>

                <div class="postbox" style="margin-top: 20px;">
                    <div class="inside">
                        <h3><?php _e('Test Email', 'alttag-registrations'); ?></h3>
                        <p class="description">
                            <?php _e('Before sending to all participants, you can send a test email to verify how it looks.', 'alttag-registrations'); ?>
                            <?php
                            if (!empty($participants)) {
                                $first_participant = reset($participants);
                                printf(
                                    __('The test email will use data from: <strong>%s %s (%s)</strong>', 'alttag-registrations'),
                                    esc_html($first_participant['first_name']),
                                    esc_html($first_participant['last_name']),
                                    esc_html($first_participant['email'])
                                );
                            }
                            ?>
                        </p>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="test_email_address"><?php _e('Test Email Address', 'alttag-registrations'); ?></label>
                                </th>
                                <td>
                                    <input type="email" name="test_email_address" id="test_email_address" class="regular-text"
                                           value="<?php echo esc_attr($saved_test_email); ?>"
                                           placeholder="<?php esc_attr_e('your@email.com', 'alttag-registrations'); ?>">
                                    <p class="description">
                                        <?php _e('Enter the email address where you want to receive the test email.', 'alttag-registrations'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Send Email', 'alttag-registrations'); ?>">
                    <input type="submit" name="send_test" id="send_test" class="button button-secondary" value="<?php _e('Send Test Email', 'alttag-registrations'); ?>">
                    <a href="<?php echo admin_url('edit.php?post_type=participant'); ?>" class="button"><?php _e('Cancel', 'alttag-registrations'); ?></a>
                </p>
            </form>

            <script>
            jQuery(document).ready(function($) {
                // Handle test button click
                $('#send_test').on('click', function(e) {
                    e.preventDefault();
                    e.stopImmediatePropagation();

                    var form = $(this).closest('form');
                    var actionInput = form.find('input[name="action"]');

                    // Change the action field value to test email
                    actionInput.val('send_test_participant_email');

                    // Force TinyMCE to save content to textarea before submit
                    if (typeof tinyMCE !== 'undefined') {
                        tinyMCE.triggerSave();
                    }

                    // Use HTMLFormElement.prototype.submit to bypass the conflict
                    HTMLFormElement.prototype.submit.call(form[0]);

                    return false;
                });
            });
            </script>
            
            <script>
            jQuery(document).ready(function($) {
                // Toggle template name field
                $('#save_as_template').change(function() {
                    if ($(this).is(':checked')) {
                        $('#new_template_name').show().prop('required', true);
                    } else {
                        $('#new_template_name').hide().prop('required', false);
                    }
                });
            });
            </script>
        </div>
        <?php
    }

    /**
     * Get absolute path from relative path
     *
     * @param string $relative_path The relative path from WordPress root
     * @return string The absolute file path
     */
    private function getAbsolutePathFromRelative($relative_path)
    {
        // Get WordPress root directory
        $wp_root = ABSPATH;
        
        // Replace backslashes with forward slashes for consistency
        $wp_root = str_replace('\\', '/', $wp_root);
        $relative_path = str_replace('\\', '/', $relative_path);
        
        // Remove leading slash if present
        if (substr($relative_path, 0, 1) === '/') {
            $relative_path = substr($relative_path, 1);
        }
        
        // Combine paths
        $absolute_path = $wp_root . $relative_path;
        
        return $absolute_path;
    }

    /**
     * Handle sending participant email
     *
     * @return void
     */
    public function handleSendParticipantEmail()
    {
        // Verify nonce
        if (!isset($_POST['email_nonce']) || !wp_verify_nonce($_POST['email_nonce'], 'send_participant_email')) {
            wp_die(__('Security check failed', 'alttag-registrations'));
        }

        // Verify user capabilities
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to send emails to participants', 'alttag-registrations'));
        }

        // Get email session ID
        $email_session_id = isset($_POST['email_session_id']) ? sanitize_text_field($_POST['email_session_id']) : '';
        if (empty($email_session_id)) {
            wp_die(__('Invalid email session', 'alttag-registrations'));
        }

        // Get participant IDs from transient
        $participant_ids = get_transient('alttag_email_session_' . $email_session_id);
        if (empty($participant_ids)) {
            wp_die(__('Email session expired or invalid. Please select participants again.', 'alttag-registrations'));
        }

        // Delete the session transient to prevent duplicate sends
        delete_transient('alttag_email_session_' . $email_session_id);

        // Get email data
        $subject = isset($_POST['email_subject']) ? sanitize_text_field($_POST['email_subject']) : '';
        $content = isset($_POST['email_content']) ? wp_kses_post($_POST['email_content']) : '';
        $include_ticket = isset($_POST['include_ticket']) && $_POST['include_ticket'] === '1';
        $include_invoice = isset($_POST['include_invoice']) && $_POST['include_invoice'] === '1';
        $save_as_template = isset($_POST['save_as_template']) && $_POST['save_as_template'] === '1';
        $template_name = isset($_POST['new_template_name']) ? sanitize_text_field($_POST['new_template_name']) : '';

        if (empty($subject) || empty($content)) {
            wp_die(__('Email subject and content are required.', 'alttag-registrations'));
        }

        // Save as template if requested
        if ($save_as_template && !empty($template_name)) {
            $templates = get_option('alttag_email_templates', []);
            $templates[$template_name] = [
                'subject' => $subject,
                'content' => $content,
                'created' => current_time('mysql'),
            ];
            update_option('alttag_email_templates', $templates);
        }

        // Send emails
        $success_count = 0;
        $error_count = 0;

        // Send the email to each participant with their own attachments
        foreach ($participant_ids as $participant_id) {
            $participant = $this->manager->getParticipantDetails($participant_id);
            if (!$participant || empty($participant['email'])) {
                $error_count++;
                continue;
            }

            // Replace placeholders in subject and content
            $personalized_subject = $subject;
            $personalized_content = $content;

            // Replace standard meta field placeholders
            foreach ($participant as $key => $value) {
                if (is_string($value)) {
                    $personalized_subject = str_replace('{' . $key . '}', $value, $personalized_subject);
                    $personalized_content = str_replace('{' . $key . '}', $value, $personalized_content);
                }
            }

            // Replace special placeholders
            $event_name = \Alttag\Registrations\get_event_name();
            $event_dates_string = \Alttag\Registrations\get_event_dates_string();
            $event_location = \Alttag\Registrations\get_event_location();

            $personalized_subject = str_replace('{event_name}', $event_name, $personalized_subject);
            $personalized_subject = str_replace('{event_dates}', $event_dates_string, $personalized_subject);
            $personalized_subject = str_replace('{event_location}', $event_location, $personalized_subject);

            $personalized_content = str_replace('{event_name}', $event_name, $personalized_content);
            $personalized_content = str_replace('{event_dates}', $event_dates_string, $personalized_content);
            $personalized_content = str_replace('{event_location}', $event_location, $personalized_content);

            // Add ticket and invoice links
            if (!empty($participant['ticket_url'])) {
                $ticket_link = '<a href="' . esc_url($participant['ticket_url']) . '" target="_blank">' . __('Download Ticket', 'alttag-registrations') . '</a>';
                $personalized_content = str_replace('{ticket_link}', $ticket_link, $personalized_content);
            } else {
                $personalized_content = str_replace('{ticket_link}', __('Ticket not available', 'alttag-registrations'), $personalized_content);
            }

            if (!empty($participant['invoice_url'])) {
                $invoice_link = '<a href="' . esc_url($participant['invoice_url']) . '" target="_blank">' . __('Download Invoice', 'alttag-registrations') . '</a>';
                $personalized_content = str_replace('{invoice_link}', $invoice_link, $personalized_content);
            } else {
                $personalized_content = str_replace('{invoice_link}', __('Invoice not available', 'alttag-registrations'), $personalized_content);
            }

            // Allow plugins to modify the personalized content
            $personalized_subject = apply_filters('alttag_registrations_email_subject', $personalized_subject, $participant, $participant_id);
            $personalized_content = apply_filters('alttag_registrations_email_content', $personalized_content, $participant, $participant_id);

            // Convert content to table-based layout for better email client compatibility
            $personalized_content = \Alttag\Registrations\EmailWrapper::convertToTableLayout($personalized_content, $personalized_subject);

            // Wrap content in Outlook-compatible HTML structure
            $preheader = wp_strip_all_tags(substr($personalized_subject, 0, 100));
            $personalized_content = \Alttag\Registrations\EmailWrapper::wrap($personalized_content, $preheader);

            $headers = ['Content-Type: text/html; charset=UTF-8'];

            // Prepare attachments for THIS participant only
            $attachments = Manager::prepareEmailAttachments(
                $participant,
                $include_ticket,
                $include_invoice
            );

            $sent = wp_mail(
                $participant['email'],
                $personalized_subject,
                $personalized_content,
                $headers,
                $attachments
            );

            if ($sent) {
                $success_count++;

                // Log email sent in participant history
                $state = \Alttag\Registrations\ParticipantState::get($participant_id);
                if ($state) {
                    $state->addToHistory(__('Email sent', 'alttag-registrations')
                        . ' - ' . __('Subject', 'alttag-registrations') . ': ' . $personalized_subject);
                }
            } else {
                $error_count++;
            }
        }

        // Delete the main transient as well
        delete_transient('alttag_email_participants');

        // Redirect back with status
        $redirect_url = add_query_arg(
            [
                'emails_sent' => $success_count,
                'emails_failed' => $error_count,
            ],
            admin_url('edit.php?post_type=participant')
        );

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Handle sending test participant email
     *
     * @return void
     */
    public function handleSendTestParticipantEmail()
    {
        // Verify nonce
        if (!isset($_POST['email_nonce']) || !wp_verify_nonce($_POST['email_nonce'], 'send_participant_email')) {
            wp_die(__('Security check failed', 'alttag-registrations'));
        }

        // Verify user capabilities
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to send emails to participants', 'alttag-registrations'));
        }

        // Get email session ID
        $email_session_id = isset($_POST['email_session_id']) ? sanitize_text_field($_POST['email_session_id']) : '';
        if (empty($email_session_id)) {
            wp_die(__('Invalid email session', 'alttag-registrations'));
        }

        // Get participant IDs from transient (don't delete it, we might need it for actual send)
        $participant_ids = get_transient('alttag_email_session_' . $email_session_id);
        if (empty($participant_ids)) {
            wp_die(__('Email session expired or invalid. Please select participants again.', 'alttag-registrations'));
        }

        // Get test email address
        $test_email = isset($_POST['test_email_address']) ? sanitize_email($_POST['test_email_address']) : '';

        if (empty($test_email) || !is_email($test_email)) {
            wp_die(__('Please provide a valid test email address.', 'alttag-registrations'));
        }

        // Get email data
        $subject = isset($_POST['email_subject']) ? sanitize_text_field($_POST['email_subject']) : '';
        $content = isset($_POST['email_content']) ? wp_kses_post($_POST['email_content']) : '';
        $include_ticket = isset($_POST['include_ticket']) && $_POST['include_ticket'] === '1';
        $include_invoice = isset($_POST['include_invoice']) && $_POST['include_invoice'] === '1';

        if (empty($subject) || empty($content)) {
            wp_die(__('Email subject and content are required.', 'alttag-registrations'));
        }

        // Send test emails for each selected participant
        $success_count = 0;
        $error_count = 0;

        foreach ($participant_ids as $participant_id) {
            $participant = $this->manager->getParticipantDetails($participant_id);

            if (!$participant) {
                $error_count++;
                continue;
            }

            // Replace placeholders in subject and content
            $personalized_subject = $subject;
            $personalized_content = $content;

            // Replace standard meta field placeholders
            foreach ($participant as $key => $value) {
                if (is_string($value)) {
                    $personalized_subject = str_replace('{' . $key . '}', $value, $personalized_subject);
                    $personalized_content = str_replace('{' . $key . '}', $value, $personalized_content);
                }
            }

            // Replace special placeholders
            $event_name = \Alttag\Registrations\get_event_name();
            $event_dates_string = \Alttag\Registrations\get_event_dates_string();
            $event_location = \Alttag\Registrations\get_event_location();

            $personalized_subject = str_replace('{event_name}', $event_name, $personalized_subject);
            $personalized_subject = str_replace('{event_dates}', $event_dates_string, $personalized_subject);
            $personalized_subject = str_replace('{event_location}', $event_location, $personalized_subject);

            $personalized_content = str_replace('{event_name}', $event_name, $personalized_content);
            $personalized_content = str_replace('{event_dates}', $event_dates_string, $personalized_content);
            $personalized_content = str_replace('{event_location}', $event_location, $personalized_content);

            // Add ticket and invoice links
            if (!empty($participant['ticket_url'])) {
                $ticket_link = '<a href="' . esc_url($participant['ticket_url']) . '" target="_blank">' . __('Download Ticket', 'alttag-registrations') . '</a>';
                $personalized_content = str_replace('{ticket_link}', $ticket_link, $personalized_content);
            } else {
                $personalized_content = str_replace('{ticket_link}', __('Ticket not available', 'alttag-registrations'), $personalized_content);
            }

            if (!empty($participant['invoice_url'])) {
                $invoice_link = '<a href="' . esc_url($participant['invoice_url']) . '" target="_blank">' . __('Download Invoice', 'alttag-registrations') . '</a>';
                $personalized_content = str_replace('{invoice_link}', $invoice_link, $personalized_content);
            } else {
                $personalized_content = str_replace('{invoice_link}', __('Invoice not available', 'alttag-registrations'), $personalized_content);
            }

            // Allow plugins to modify the personalized content
            $personalized_subject = apply_filters('alttag_registrations_email_subject', $personalized_subject, $participant, $participant_id);
            $personalized_content = apply_filters('alttag_registrations_email_content', $personalized_content, $participant, $participant_id);

            // Convert content to table-based layout for better email client compatibility
            $personalized_content = \Alttag\Registrations\EmailWrapper::convertToTableLayout($personalized_content, $personalized_subject);

            // Wrap content in Outlook-compatible HTML structure
            $preheader = wp_strip_all_tags(substr($personalized_subject, 0, 100));
            $personalized_content = \Alttag\Registrations\EmailWrapper::wrap($personalized_content, $preheader);

            $headers = ['Content-Type: text/html; charset=UTF-8'];

            // Prepare attachments using static helper method from Manager
            $attachments = Manager::prepareEmailAttachments($participant, $include_ticket, $include_invoice);

            // Temporarily override wp_mail recipient for test emails using phpmailer_init
            // This ensures the test email goes to the specified address, not the participant
            $force_test_email = $test_email;
            $phpmailer_override = function ($phpmailer) use ($force_test_email) {
                $phpmailer->clearAddresses();
                $phpmailer->clearAllRecipients();
                $phpmailer->addAddress($force_test_email);
            };
            add_action('phpmailer_init', $phpmailer_override, 999);

            // Send test email
            $sent = wp_mail(
                $test_email,
                $personalized_subject,
                $personalized_content,
                $headers,
                $attachments
            );

            // Remove the action after sending
            remove_action('phpmailer_init', $phpmailer_override, 999);

            if ($sent) {
                $success_count++;
            } else {
                $error_count++;
            }
        }

        // Get template name from POST if set
        $template_name = isset($_POST['email_template']) ? sanitize_text_field($_POST['email_template']) : '';

        // Save form values to transient so they persist after redirect
        $form_values = [
            'subject' => $subject,
            'content' => $content,
            'test_email' => $test_email,
            'include_ticket' => $include_ticket,
            'include_invoice' => $include_invoice,
            'template_name' => $template_name,
        ];
        set_transient('alttag_email_form_values_' . get_current_user_id(), $form_values, 60 * 15);

        // Redirect back with status
        $redirect_url = add_query_arg(
            [
                'test_email_sent' => $success_count > 0 ? '1' : '0',
                'test_email_to' => urlencode($test_email),
                'test_count' => $success_count,
            ],
            admin_url('admin.php?page=alttag-email')
        );

        // Re-set the transient to return to the email form
        set_transient('alttag_email_participants', $participant_ids, 60 * 15);

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Handle resending email with ticket and invoice
     *
     * @return void
     */
    public function handleResendEmailWithTicketAndInvoice()
    {
        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'resend_email_with_ticket_and_invoice_' . $_GET['participant_id'])) {
            wp_die(__('Security check failed', 'alttag-registrations'));
        }

        // Verify user capabilities
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to perform this action', 'alttag-registrations'));
        }

        // Get participant ID
        $participant_id = isset($_GET['participant_id']) ? intval($_GET['participant_id']) : 0;
        if (!$participant_id) {
            wp_die(__('Invalid request', 'alttag-registrations'));
        }

        // Resend email
        $result = $this->resendEmailWithTicketAndInvoice([$participant_id]);

        // Check if we should redirect back to the participant detail page
        $redirect_to = isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '';

        if ($redirect_to === 'detail') {
            // Redirect back to participant detail page
            $redirect_url = admin_url('post.php?post=' . $participant_id . '&action=edit');
            $redirect_url = add_query_arg(
                [
                    'emails_sent' => $result['success'],
                    'emails_failed' => $result['error'],
                ],
                $redirect_url
            );
        } else {
            // Redirect back to participants list
            $redirect_url = add_query_arg(
                [
                    'emails_sent' => $result['success'],
                    'emails_failed' => $result['error'],
                ],
                admin_url('edit.php?post_type=participant')
            );
        }

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Resend email with ticket and invoice to multiple participants
     *
     * @param array $participant_ids Array of participant IDs
     *
     * @return array Array with counts of success and failure
     */
    public function resendEmailWithTicketAndInvoice($participant_ids)
    {
        $success_count = 0;
        $error_count = 0;

        foreach ($participant_ids as $participant_id) {
            $participant = $this->manager->getParticipantDetails($participant_id);
            if (!$participant || empty($participant['email'])) {
                $error_count++;
                continue;
            }

            try {
                // Use WordPress HTTP API to make an internal request to trigger the email resend
                $url = add_query_arg(
                    [
                        'resend_confirmation_email' => $participant_id,
                    ],
                    home_url()
                );

                $response = wp_remote_get($url, [
                    'timeout' => 15,
                    'sslverify' => false,
                    'blocking' => false // Non-blocking request
                ]);

                // Log email sent in participant history
                $state = \Alttag\Registrations\ParticipantState::get($participant_id);
                if ($state) {
                    $state->addToHistory(__('Ticket and invoice email resent', 'alttag-registrations'));
                }

                $success_count++;
            } catch (Exception $e) {
                error_log('Error resending email for participant #' . $participant_id . ': ' . $e->getMessage());
                $error_count++;
            }
        }

        return [
            'success' => $success_count,
            'error' => $error_count
        ];
    }
}
