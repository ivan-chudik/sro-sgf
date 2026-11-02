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

    private function logo_repeater() {
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
        return $logo->get_controls();
    }

    private function logo_group( $n, $section_label, $label, array $defaults ) {
        $sfx = $n === 1 ? '' : (string) $n;

        $this->start_controls_section( 'section_group' . $n, [
            'label' => $section_label,
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'group' . $n . '_show', 'Zobraziť skupinu' );
        $this->xn_show_control( 'label' . $sfx . '_show', 'Zobraziť nadpis' );
        $this->add_control( 'label' . $sfx, [
            'label'     => 'Nadpis',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => $label,
            'condition' => [ 'label' . $sfx . '_show' => 'yes' ],
        ]);
        $this->add_control( 'logos' . $sfx, [
            'label'       => 'Logá',
            'type'        => \Elementor\Controls_Manager::REPEATER,
            'fields'      => $this->logo_repeater(),
            'title_field' => '{{{ alt }}}',
            'default'     => $defaults,
        ]);
        $this->end_controls_section();
    }

    protected function register_controls() {

        $l = function( $file, $alt, $h ) {
            return [ 'image' => [ 'url' => xn_asset( 'logos/' . $file ) ], 'alt' => $alt, 'height' => [ 'size' => $h, 'unit' => 'px' ] ];
        };

        $this->logo_group( 1, 'Organizátori', 'Organizátori', [
            $l( 'cn-org-sgf-claim-en-white.png', 'Slovenská gymnastická federácia', 42 ),
            $l( 'cn-org-sk-sog-nitra-mg-white.png', 'ŠK ŠOG Nitra — moderná gymnastika', 46 ),
        ]);
        $this->logo_group( 2, 'Partneri', 'Partneri', [
            $l( 'cn-partner-wg-white.png', 'World Gymnastics', 40 ),
            $l( 'cn-partner-eg-white.png', 'European Gymnastics', 44 ),
            $l( 'cn-partner-mcras-sk-white.png', 'Ministerstvo cestovného ruchu a športu SR', 46 ),
            $l( 'cn-partner-sss-nitra-white.png', 'Stredná športová škola Nitra', 62 ),
        ]);

        $this->start_controls_section( 'section_layout', [
            'label' => 'Rozloženie',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->add_responsive_control('gap', [
            'label'      => 'Medzera medzi logami',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 120 ] ],
            'selectors'  => [ '{{WRAPPER}} .partners__row' => 'gap: {{SIZE}}{{UNIT}};' ],
        ]);
        $this->add_responsive_control('group_gap', [
            'label'      => 'Medzera medzi skupinami',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 200 ] ],
            'selectors'  => [ '{{WRAPPER}} .partners__groups' => 'gap: {{SIZE}}{{UNIT}};' ],
        ]);
        $this->xn_show_control( 'divider_show', 'Deliaca čiara medzi skupinami' );
        $this->end_controls_section();

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'label', 'Nadpisy skupín', '{{WRAPPER}} .partners__label' ],
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
        $this->add_control('divider_color', [
            'label'     => 'Farba deliacej čiary',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .partners__groups.has-divider .partners__group + .partners__group' => 'border-color: {{VALUE}};' ],
        ]);
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
