<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_Hero extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'hero'; }
    public function get_title()      { return 'CN · Hero'; }
    public function get_icon()       { return 'eicon-banner'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'hero-script' ]; }
    public function get_style_depends()  { return [ 'hero-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Obsah',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_anchor_control( 'top' );

        $this->xn_show_control( 'date_show', 'Zobraziť dátum' );
        $this->add_control('date_text', [
            'label'     => 'Dátum',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => '26. – 29. novembra 2026 · Nitra',
            'condition' => [ 'date_show' => 'yes' ],
        ]);
        $this->add_control('title', [
            'label'   => 'Nadpis (H1)',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Christmas Nitra',
        ]);
        $this->xn_show_control( 'subtitle_show', 'Zobraziť podnadpis' );
        $this->add_control('subtitle', [
            'label'     => 'Podnadpis',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Slovak Rhythmic Gymnastics Open',
            'condition' => [ 'subtitle_show' => 'yes' ],
        ]);
        $this->xn_show_control( 'claim_show', 'Zobraziť claim' );
        $this->add_control('claim', [
            'label'       => 'Claim',
            'type'        => \Elementor\Controls_Manager::TEXTAREA,
            'rows'        => 3,
            'default'     => 'Štyri dni. Jedna hala. <b>Svetová špička modernej gymnastiky</b> — naživo v Nitre alebo v livestreame kdekoľvek.',
            'description' => 'Text v &lt;b&gt;…&lt;/b&gt; sa zobrazí gradientom.',
            'condition'   => [ 'claim_show' => 'yes' ],
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_image', [
            'label' => 'Obrázok',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'image_show', 'Zobraziť obrázok' );
        $this->xn_media_controls( 'image', 'Gymnastka', xn_asset( 'img/xn-gymnast.png' ), '{{WRAPPER}} .hero__gym', 'Moderná gymnastka so stuhou' );
        $this->xn_show_control( 'ribbons_show', 'Zobraziť dekoratívne stuhy' );
        $this->end_controls_section();

        $this->start_controls_section( 'section_buttons', [
            'label' => 'Tlačidlá',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $mode_options = [ '' => '— nič —', 'live' => 'Naživo', 'stream' => 'Livestream' ];
        $this->xn_button_controls( 'btn1', 'Tlačidlo 1', 'Chcem byť pri tom', 'primary', '#program' );
        $this->add_control('btn1_mode', [
            'label'       => 'Prepnúť tab v cenníku na',
            'type'        => \Elementor\Controls_Manager::SELECT,
            'default'     => 'live',
            'options'     => $mode_options,
            'condition'   => [ 'btn1_show' => 'yes' ],
        ]);
        $this->xn_button_controls( 'btn2', 'Tlačidlo 2', 'Sledovať online', 'grad', '#program' );
        $this->add_control('btn2_mode', [
            'label'     => 'Prepnúť tab v cenníku na',
            'type'      => \Elementor\Controls_Manager::SELECT,
            'default'   => 'stream',
            'options'   => $mode_options,
            'condition' => [ 'btn2_show' => 'yes' ],
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_countdown', [
            'label' => 'Odpočet',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'countdown_show', 'Zobraziť odpočet' );
        $this->add_control('countdown_target', [
            'label'       => 'Cieľový dátum (ISO)',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => '2026-11-26T09:00:00+01:00',
            'description' => 'Formát RRRR-MM-DDTHH:MM:SS+01:00',
            'condition'   => [ 'countdown_show' => 'yes' ],
        ]);
        $this->add_control('countdown_label', [
            'label'     => 'Text pri čísle',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'dní do štartu',
            'condition' => [ 'countdown_show' => 'yes' ],
        ]);
        $this->add_control('countdown_note', [
            'label'       => 'Poznámka',
            'type'        => \Elementor\Controls_Manager::TEXTAREA,
            'rows'        => 2,
            'default'     => 'Early Bird ceny do <em>31. 10. 2026</em>',
            'description' => 'Text v &lt;em&gt;…&lt;/em&gt; sa zvýrazní.',
            'condition'   => [ 'countdown_show' => 'yes' ],
        ]);
        $this->end_controls_section();

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'date',     'Dátum',            '{{WRAPPER}} .hero__date' ],
            [ 'title',    'Nadpis',           '{{WRAPPER}} .hero__title', 'gradient' ],
            [ 'subtitle', 'Podnadpis',        '{{WRAPPER}} .hero__sub', 'gradient' ],
            [ 'claim',    'Claim',            '{{WRAPPER}} .hero__claim' ],
            [ 'cd_num',   'Odpočet — číslo',  '{{WRAPPER}} .hero__cd-num', 'gradient' ],
            [ 'cd_label', 'Odpočet — text',   '{{WRAPPER}} .hero__cd-label' ],
            [ 'cd_note',  'Odpočet — poznámka', '{{WRAPPER}} .hero__cd-note' ],
        ]);
        $this->xn_style_buttons();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
