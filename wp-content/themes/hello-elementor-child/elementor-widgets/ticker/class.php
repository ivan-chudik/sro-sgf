<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_Ticker extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'ticker'; }
    public function get_title()      { return 'CN · Ticker'; }
    public function get_icon()       { return 'eicon-animation-text'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'ticker-script' ]; }
    public function get_style_depends()  { return [ 'ticker-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Položky',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);

        $repeater = new \Elementor\Repeater();
        $repeater->add_control('text', [
            'label'   => 'Text',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Stuha',
        ]);
        $repeater->add_control('grad', [
            'label'        => 'Gradient',
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'return_value' => 'yes',
            'default'      => 'yes',
        ]);

        $grad = function( $t ) { return [ 'text' => $t, 'grad' => 'yes' ]; };
        $this->add_control('items', [
            'label'       => 'Položky',
            'type'        => \Elementor\Controls_Manager::REPEATER,
            'fields'      => $repeater->get_controls(),
            'title_field' => '{{{ text }}}',
            'default'     => [
                $grad( 'Švihadlo' ), $grad( 'Obruč' ), $grad( 'Lopta' ), $grad( 'Kužele' ), $grad( 'Stuha' ),
                [ 'text' => 'Christmas Nitra 2026', 'grad' => '' ],
                [ 'text' => '26. – 29. 11. · Nitra', 'grad' => '' ],
            ],
        ]);

        $this->add_control('duration', [
            'label'      => 'Rýchlosť — dĺžka jedného cyklu (s)',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 's' ],
            'range'      => [ 's' => [ 'min' => 10, 'max' => 120 ] ],
            'selectors'  => [ '{{WRAPPER}} .ticker__track' => 'animation-duration: {{SIZE}}s;' ],
        ]);

        $this->end_controls_section();

        $this->xn_style_section( '{{WRAPPER}} .custom-ticker', 'Pás' );
        $this->xn_style_texts([
            [ 'item', 'Text položiek', '{{WRAPPER}} .ticker__item' ],
        ]);
        $this->start_controls_section('style_lines', [
            'label' => 'Čiary',
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('line_color', [
            'label'     => 'Farba čiar',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .custom-ticker' => 'border-color: {{VALUE}};' ],
        ]);
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
