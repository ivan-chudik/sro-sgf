<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;

/**
 * Handles QR code generation and serving functionality
 */
class QRCodeManager
{
    private $verificationManager;

    public function registerHooks()
    {
        // Register QR code display handler
        add_action('parse_request', [$this, 'handleQrCodeRequest'], 1);

        // Inline-embed the QR PNG into outgoing mail bodies that reference
        // the /qr-code/{symbol} URL. Mail clients with strict remote-image
        // policies (Apple Mail, Gmail mobile app) ignore <img src="https://…">
        // — referencing the embedded image via cid: makes them render reliably.
        add_action('phpmailer_init', [$this, 'inlineQrInOutgoingMail'], 999);
    }

    /**
     * Detect /qr-code/{variable_symbol} URLs in the outgoing mail body and
     * replace them with a cid: reference whose binary is attached as an
     * inline embedded image. Idempotent — silently skips when no match,
     * when the symbol resolves to no participant, or when the cached PNG
     * meta is missing.
     */
    public function inlineQrInOutgoingMail($phpmailer): void
    {
        if (!isset($phpmailer->Body) || !is_string($phpmailer->Body)) {
            return;
        }
        $body = $phpmailer->Body;
        if (strpos($body, '/qr-code/') === false) {
            return;
        }

        $home = preg_quote(home_url(), '#');
        if (!preg_match('#' . $home . '/qr-code/([^"\'\\\\s<>]+)#i', $body, $match)) {
            return;
        }
        $url = $match[0];
        $variable_symbol = rawurldecode($match[1]);
        if ($variable_symbol === '') {
            return;
        }

        $hits = get_posts([
            'post_type'      => 'participant',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => 'variable_symbol',
            'meta_value'     => $variable_symbol,
            'no_found_rows'  => true,
        ]);
        if (empty($hits)) {
            return;
        }

        $qr_b64 = get_post_meta((int) $hits[0], 'qr_code_img', true);
        if (!is_string($qr_b64) || $qr_b64 === '') {
            return;
        }
        $binary = base64_decode($qr_b64, true);
        if ($binary === false) {
            return;
        }

        // CID needs to be unique within the message but stable enough to match
        // every img tag that points at this symbol's QR endpoint.
        $cid = 'qr-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $variable_symbol);

        $phpmailer->addStringEmbeddedImage($binary, $cid, 'qr-code.png', 'base64', 'image/png');
        $phpmailer->Body = str_replace($url, 'cid:' . $cid, $body);
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
     * Handle QR code display request
     *
     * @param WP $wp The WordPress query object
     */
    public function handleQrCodeRequest($wp)
    {
        if (isset($wp->query_vars['qr_code_symbol'])) {
            $variable_symbol = $wp->query_vars['qr_code_symbol'];
            $this->displayQRCodeImage($variable_symbol);
            exit;
        }
    }

    /**
     * Generate QR code URL for verification
     *
     * @param string $variableSymbol The variable symbol
     * @return string The QR code URL
     */
    public function generateQRCodeUrl($variableSymbol)
    {
        return home_url("/qr-code/{$variableSymbol}");
    }

    /**
     * Display QR code image
     *
     * @param string $variableSymbol The variable symbol
     */
    public function displayQRCodeImage($variableSymbol)
    {
        try {
            $verification_url = $this->verificationManager->getVerificationUrl($variableSymbol);

            $qrCode = QrCode::create($verification_url)
                ->setSize(300)
                ->setMargin(10)
                ->setErrorCorrectionLevel(new ErrorCorrectionLevelHigh());

            $writer = new PngWriter();
            $result = $writer->write($qrCode);

            header('Content-Type: image/png');
            echo $result->getString();
            exit;
        } catch (\Exception $e) {
            error_log("Error generating QR code: " . $e->getMessage());
            status_header(500);
            echo "Error generating QR code. Please check server logs.";
            exit;
        }
    }

    /**
     * Generate QR code image for admin display and save as base64
     *
     * @param int $participantId The participant ID
     * @param string $variableSymbol The variable symbol
     * @return bool True on success, false on failure
     */
    public function generateAndSaveQRCodeImage($participantId, $variableSymbol)
    {
        try {
            $verification_url = $this->verificationManager->getVerificationUrl($variableSymbol);
            $qrCode = QrCode::create($verification_url)
                ->setSize(300)
                ->setMargin(10)
                ->setErrorCorrectionLevel(new ErrorCorrectionLevelHigh());

            $writer = new PngWriter();
            $result = $writer->write($qrCode);

            // Convert to base64 for storage
            $qrCodeImage = base64_encode($result->getString());
            update_post_meta($participantId, 'qr_code_img', $qrCodeImage);

            // Also save the QR code URL
            $qrCodeUrl = $this->generateQRCodeUrl($variableSymbol);
            update_post_meta($participantId, 'qr_code_url', $qrCodeUrl);

            // ParticipantState keeps request-level meta cache. Clear it so
            // email/ticket flows in the same request see the fresh QR data.
            \Alttag\Registrations\ParticipantState::clearCache($participantId);

            return true;
        } catch (\Exception $e) {
            error_log("Error generating QR code for participant {$participantId}: " . $e->getMessage());
            return false;
        }
    }
}
