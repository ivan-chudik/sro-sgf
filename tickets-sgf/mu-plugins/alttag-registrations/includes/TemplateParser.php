<?php

namespace Alttag\Registrations;

if (!defined('ABSPATH')) {
    exit;
}

class TemplateParser
{
    private $data = [];
    private $template = '';

    public function __construct($template, $data)
    {
        $this->template = $template;
        $this->data = $data;
    }

    public function render()
    {
        try {
            // Extract variables to make them available in the template
            extract($this->data);

            // Start output buffering
            ob_start();

            // Evaluate the PHP code
            eval('?>' . $this->template);

            // Get the contents and clean the buffer
            $content = ob_get_clean();

            return trim($content);
        } catch (\Exception $e) {
            error_log('Template error: ' . $e->getMessage());
            return '';
        }
    }
} 