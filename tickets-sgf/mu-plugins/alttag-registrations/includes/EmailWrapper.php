<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email wrapper class for Outlook-compatible HTML emails
 */
class EmailWrapper
{
    /**
     * Wrap email content in Outlook-compatible HTML structure
     *
     * @param string $content The email content to wrap
     * @param string $preheader Optional preheader text
     * @return string The wrapped email content
     */
    public static function wrap($content, $preheader = '')
    {
        // Minimal styles for email clients that support them
        $styles = self::getMinimalStyles();

        $primary_color = apply_filters('alttag_registrations_email_primary_color', '#2B5C63');

        $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
        <html xmlns="http://www.w3.org/1999/xhtml">
            <head>
                <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
                <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
                <meta name="x-apple-disable-message-reformatting">
                <meta http-equiv="X-UA-Compatible" content="IE=edge">

                <!--[if mso]>
                <style type="text/css">
                    body, table, td, a { font-family: Arial, sans-serif !important; }
                    table { border-collapse: collapse; }
                </style>
                <![endif]-->
                <style type="text/css">
                    ' . $styles . '
                </style>
            </head>
            <body style="margin: 0; padding: 0; background-color: #f5f5f5; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.6; color: ' . $primary_color . ';">
                <!-- 100% background wrapper -->
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f5f5f5; margin: 0; padding: 0;">
                    <tr>
                        <td align="center" style="padding: 40px 20px;">
                            <!-- 650px container with white background -->
                            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="width: 100%; max-width: 650px; background-color: #ffffff; margin: 0 auto; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                                <tr>
                                    <td style="padding: 0; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.7; color: ' . $primary_color . ';">
                                        <!-- Email content goes here -->
                                        ' . $content . '
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
        </html>';

        return $html;
    }

    /**
     * Get preheader HTML (hidden text that appears in email preview)
     *
     * @param string $preheader
     * @return string
     */
    private static function getPreheaderHtml($preheader)
    {
        if (empty($preheader)) {
            return '';
        }

        return '<div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">
            ' . esc_html($preheader) . '
        </div>';
    }

    /**
     * Get minimal email styles (only for email clients that support CSS)
     *
     * @return string
     */
    private static function getMinimalStyles()
    {
        return '
        /* Reset styles */
        body, table, td, a {
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }

        table, td {
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
        }

        img {
            -ms-interpolation-mode: bicubic;
            border: 0;
            height: auto;
            line-height: 100%;
            outline: none;
            text-decoration: none;
        }

        /* Responsive */
        @media only screen and (max-width: 600px) {
            .email-container {
                width: 100% !important;
            }
        }
        ';
    }

    /**
     * Convert content to table-based layout with proper styling
     * Adds header, footer, padding and formatting for custom emails
     *
     * @param string $content The email content
     * @param string $subject Optional email subject for header
     * @return string
     */
    public static function convertToTableLayout($content, $subject = '')
    {
        // Check if content already has styled header/footer (order processing emails)
        // These already have proper inline styles
        if (strpos($content, 'class="header"') !== false ||
            strpos($content, 'border-radius: 12px 12px 0 0') !== false) {
            return $content;
        }

        // Get colors from filters
        $primary_color = apply_filters(
            'alttag_registrations_email_primary_color',
            '#2B5C63'
        );
        $accent_color = apply_filters(
            'alttag_registrations_email_accent_color',
            '#FFE500'
        );

        // Use subject for header, fallback to event name
        $ctx = \Alttag\Registrations\RegistrationContext::current();
        $header_title = $subject;
        if (empty($header_title)) {
            $header_title = $ctx->eventName();
        }

        // Get organization team name for footer
        $organization_name = $ctx->teamName();

        // Get footer info text
        $footer_info_text = apply_filters('alttag_registrations_footer_info_text', '', null);

        // Convert bare newlines to paragraphs / <br>. wpautop is idempotent for content
        // already wrapped in block tags, and correctly adds <br> for single newlines
        // inside existing <p> blocks (so mixed TinyMCE + manually-typed content works).
        $content = wpautop($content);

        // Apply inline styles to paragraphs
        $content = preg_replace(
            '/<p>/',
            '<p style="margin: 0 0 15px 0; font-family: Arial, Helvetica, sans-serif; ' .
            'font-size: 15px; line-height: 1.7; color: ' . $primary_color . ';">',
            $content
        );

        // Build styled email with header, content, and footer
        $styled_content = '';

        // Header
        if (!empty($header_title)) {
            $styled_content .= '
            <div class="header" style="background: ' . $primary_color . '; padding: 40px 30px; ' .
            'text-align: center; margin: 0; border-radius: 12px 12px 0 0; width: 100%; box-sizing: border-box;">
                <h1 style="color: ' . $accent_color . '; font-family: Arial, Helvetica, sans-serif; ' .
                'font-size: 22px; font-weight: 600; margin: 0; text-align: center; letter-spacing: 0.3px; ' .
                'line-height: 1.5; width: 100%; box-sizing: border-box;">' . esc_html($header_title) . '</h1>
            </div>';
        }

        // Content
        $styled_content .= '
        <div style="width: 100%; box-sizing: border-box; padding: 35px 40px; margin: 0; background-color: #ffffff;">
            ' . $content . '
        </div>';

        // Footer
        if (!empty($organization_name) || !empty($footer_info_text)) {
            $styled_content .= '
            <div class="footer" style="width: 100%; box-sizing: border-box; background: ' . $primary_color . '; ' .
            'color: #ffffff; padding: 30px 40px; text-align: center; margin: 0; ' .
            'border-radius: 0 0 12px 12px; display: block;">';

            if (!empty($footer_info_text)) {
                $styled_content .= '<p style="width: 100%; box-sizing: border-box; color: #ffffff; ' .
                'margin: 0 0 15px 0; font-family: Arial, Helvetica, sans-serif; font-size: 14px; ' .
                'line-height: 1.6; padding: 0;">' . wp_kses_post($footer_info_text) . '</p>';
            }

            if (!empty($organization_name)) {
                $styled_content .= '<p style="width: 100%; box-sizing: border-box; color: #ffffff; ' .
                'margin: 0; font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.6; ' .
                'padding: 0;"><b style="color: ' . $accent_color . '; font-weight: 700; font-size: 17px;">' .
                esc_html($organization_name) . '</b></p>';
            }

            $styled_content .= '</div>';
        }

        return $styled_content;
    }
}
