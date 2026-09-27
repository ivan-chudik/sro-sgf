<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_Reasons extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'reasons'; }
    public function get_title()      { return 'CN · Tri dôvody + fotky'; }
    public function get_icon()       { return 'eicon-gallery-grid'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'reasons-script' ]; }
    public function get_style_depends()  { return [ 'reasons-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Hlavička sekcie',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_anchor_control( 'preco' );
        $this->xn_intro_controls( 'Prečo Christmas Nitra', 'Tri dôvody, prečo si tento víkend zarezervovať' );
        $this->xn_show_control( 'ribbons_show', 'Zobraziť dekoratívnu stuhu' );
        $this->end_controls_section();

        $this->start_controls_section( 'section_reasons', [
            'label' => 'Dôvody',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'reasons_show', 'Zobraziť dôvody' );
        $repeater = new \Elementor\Repeater();
        $repeater->add_control('num', [
            'label'   => 'Číslo',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '01',
        ]);
        $repeater->add_control('title', [
            'label'   => 'Nadpis',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $repeater->add_control('text', [
            'label'   => 'Text',
            'type'    => \Elementor\Controls_Manager::WYSIWYG,
            'default' => '',
        ]);
        $this->add_control('reasons', [
            'label'       => 'Dôvody',
            'type'        => \Elementor\Controls_Manager::REPEATER,
            'fields'      => $repeater->get_controls(),
            'title_field' => '{{{ num }}} {{{ title }}}',
            'condition'   => [ 'reasons_show' => 'yes' ],
            'default'     => [
                [ 'num' => '01', 'title' => 'Svetová špička',     'text' => '<p>Oficiálna súťaž World Gymnastics a medzinárodná pozývacia súťaž — pretekárky z Európy aj zo sveta, rozhodkyne s medzinárodným brevetom. Zostavy, ktoré inak vidíš len v televízii, tu sleduješ z hľadiska.</p>' ],
                [ 'num' => '02', 'title' => '27 rokov tradície',  'text' => '<p>Jedna z najdlhšie organizovaných súťaží modernej gymnastiky na Slovensku — 27. ročník pod hlavičkou ŠK ŠOG Nitra a Slovenskej gymnastickej federácie.</p>' ],
                [ 'num' => '03', 'title' => 'Predvianočná Nitra', 'text' => '<p>Gymnastika, hudba a sviatočná atmosféra — ideálny rodinný víkend pred Vianocami. Štyri dni programu, z ktorých si vyberieš ten svoj.</p>' ],
            ],
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_moments', [
            'label' => 'Fotky',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'moments_show', 'Zobraziť fotky' );
        $photos = new \Elementor\Repeater();
        $photos->add_control('image', [
            'label' => 'Fotka',
            'type'  => \Elementor\Controls_Manager::MEDIA,
        ]);
        $photos->add_responsive_control('image_width', [
            'label'      => 'Šírka',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ '%', 'px' ],
            'range'      => [ '%' => [ 'min' => 10, 'max' => 100 ], 'px' => [ 'min' => 20, 'max' => 800 ] ],
            'selectors'  => [ '{{WRAPPER}} {{CURRENT_ITEM}} img' => 'width: {{SIZE}}{{UNIT}};' ],
        ]);
        $photos->add_control('alt', [
            'label'   => 'Alt text',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $photos->add_control('caption', [
            'label'   => 'Popisok',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $this->add_control('moments', [
            'label'       => 'Fotky',
            'type'        => \Elementor\Controls_Manager::REPEATER,
            'fields'      => $photos->get_controls(),
            'title_field' => '{{{ caption }}}',
            'condition'   => [ 'moments_show' => 'yes' ],
            'default'     => [
                [ 'image' => [ 'url' => xn_asset( 'photo/moment-ribbon.jpg' ) ],     'alt' => 'Zostava so stuhou — Christmas Nitra', 'caption' => 'Zostava so stuhou' ],
                [ 'image' => [ 'url' => xn_asset( 'photo/moment-group-2022.jpg' ) ], 'alt' => 'Spoločná fotografia pretekárok v Mestskej hale Nitra', 'caption' => 'Medzinárodné štartové pole' ],
                [ 'image' => [ 'url' => xn_asset( 'photo/moment-podium.jpg' ) ],     'alt' => 'Stupne víťazov — Christmas Nitra', 'caption' => 'Stupne víťazov' ],
            ],
        ]);
        $this->add_responsive_control('moments_height', [
            'label'      => 'Výška fotiek',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 120, 'max' => 700 ] ],
            'selectors'  => [ '{{WRAPPER}} .reasons__moments img' => 'height: {{SIZE}}{{UNIT}};' ],
            'condition'  => [ 'moments_show' => 'yes' ],
        ]);
        $this->xn_show_control( 'credit_show', 'Zobraziť kredit fotografov' );
        $this->add_control('credit', [
            'label'     => 'Kredit',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Foto: Daniel Palhegyi, Igor Skačan · Christmas Nitra',
            'condition' => [ 'credit_show' => 'yes' ],
        ]);
        $this->end_controls_section();

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'eyebrow',      'Eyebrow',        '{{WRAPPER}} .xn-eyebrow' ],
            [ 'title',        'Nadpis sekcie',  '{{WRAPPER}} .xn-h2' ],
            [ 'reason_num',   'Dôvod — číslo',  '{{WRAPPER}} .reasons__num', 'gradient' ],
            [ 'reason_title', 'Dôvod — nadpis', '{{WRAPPER}} .reasons__title' ],
            [ 'reason_text',  'Dôvod — text',   '{{WRAPPER}} .reasons__text' ],
            [ 'caption',      'Popisok fotky',  '{{WRAPPER}} .reasons__moments figcaption' ],
            [ 'credit',       'Kredit',         '{{WRAPPER}} .reasons__credit' ],
        ]);
        $this->xn_style_cards([
            [ 'photo', 'Fotky', '{{WRAPPER}} .reasons__moments figure' ],
        ]);
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
