<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_StickyCta extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'sticky-cta'; }
    public function get_title()      { return 'CN · Mobilná CTA lišta'; }
    public function get_icon()       { return 'eicon-button'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'sticky-cta-script' ]; }
    public function get_style_depends()  { return [ 'sticky-cta-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Tlačidlá',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->add_control('info', [
            'type'            => \Elementor\Controls_Manager::RAW_HTML,
            'raw'             => 'Lišta je pripnutá k spodnému okraju a zobrazuje sa len na mobile (≤ 820 px). V editore je viditeľná vždy.',
            'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
        ]);
        $this->xn_button_controls( 'btn1', 'Tlačidlo 1', 'Vstupenky', 'primary', '', $this->xn_tickets_desc() );
        $this->xn_button_controls( 'btn2', 'Tlačidlo 2', 'Livestream', 'grad', '#livestream' );
        $this->end_controls_section();

        $this->xn_style_section( '{{WRAPPER}} .custom-sticky-cta', 'Lišta' );
        $this->xn_style_buttons();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
