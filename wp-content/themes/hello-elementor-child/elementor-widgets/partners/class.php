<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_Partners extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'partners'; }
    public function get_title()      { return 'CN · Partneri'; }
    public function get_icon()       { return 'eicon-logo'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'partners-script' ]; }
    public function get_style_depends()  { return [ 'partners-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Logá',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'label_show', 'Zobraziť nadpis' );
        $this->add_control('label', [
            'label'     => 'Nadpis',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Organizátori a partneri',
            'condition' => [ 'label_show' => 'yes' ],
        ]);

        $logo = new \Elementor\Repeater();
        $logo->add_control('image', [
            'label' => 'Logo (biele, priehľadné PNG)',
            'type'  => \Elementor\Controls_Manager::MEDIA,
        ]);
        $logo->add_responsive_control('image_width', [
            'label'      => 'Šírka',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 20, 'max' => 400 ] ],
            'selectors'  => [ '{{WRAPPER}} {{CURRENT_ITEM}} img' => 'width: {{SIZE}}{{UNIT}}; height: auto;' ],
        ]);
        $logo->add_responsive_control('height', [
            'label'      => 'Výška',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 12, 'max' => 120 ] ],
            'default'    => [ 'size' => 40, 'unit' => 'px' ],
            'selectors'  => [ '{{WRAPPER}} {{CURRENT_ITEM}} img' => 'height: {{SIZE}}{{UNIT}};' ],
        ]);
        $logo->add_control('alt', [
            'label'   => 'Názov (alt)',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $logo->add_control('link', [
            'label'       => 'Odkaz (voliteľné)',
            'type'        => \Elementor\Controls_Manager::URL,
            'placeholder' => 'https://',
        ]);

        $l = function( $file, $alt, $h ) {
            return [ 'image' => [ 'url' => xn_asset( 'logos/' . $file ) ], 'alt' => $alt, 'height' => [ 'size' => $h, 'unit' => 'px' ] ];
        };
        $this->add_control('logos', [
            'label'       => 'Logá',
            'type'        => \Elementor\Controls_Manager::REPEATER,
            'fields'      => $logo->get_controls(),
            'title_field' => '{{{ alt }}}',
            'default'     => [
                $l( 'sog-nitra-white.png', 'ŠK ŠOG Nitra — moderná gymnastika', 34 ),
                $l( 'sgf-white.png', 'Slovenská gymnastická federácia', 26 ),
                $l( 'sss-white-hd.png', 'Stredná športová škola Nitra', 56 ),
                $l( 'wg-white-hd.png', 'World Gymnastics', 40 ),
                $l( 'eg-white.png', 'European Gymnastics', 40 ),
                $l( 'min-cestovny-ruch-sport-white.png', 'Ministerstvo cestovného ruchu a športu SR', 46 ),
                $l( 'min-skolstva-white.png', 'Ministerstvo školstva, výskumu, vývoja a mládeže SR', 46 ),
            ],
        ]);

        $this->add_responsive_control('gap', [
            'label'      => 'Medzera medzi logami',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 120 ] ],
            'selectors'  => [ '{{WRAPPER}} .partners__row' => 'gap: {{SIZE}}{{UNIT}};' ],
        ]);
        $this->end_controls_section();

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'label', 'Nadpis', '{{WRAPPER}} .partners__label' ],
        ]);
        $this->start_controls_section('style_logos', [
            'label' => 'Logá',
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('logo_opacity', [
            'label'     => 'Priehľadnosť',
            'type'      => \Elementor\Controls_Manager::SLIDER,
            'range'     => [ 'px' => [ 'min' => 0.1, 'max' => 1, 'step' => 0.05 ] ],
            'selectors' => [ '{{WRAPPER}} .partners__row img' => 'opacity: {{SIZE}};' ],
        ]);
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
