<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_Venue extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'venue'; }
    public function get_title()      { return 'CN · Miesto konania'; }
    public function get_icon()       { return 'eicon-map-pin'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'venue-script' ]; }
    public function get_style_depends()  { return [ 'venue-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Obsah',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_anchor_control( 'miesto' );
        $this->xn_intro_controls( 'Miesto konania', 'Nitra, 26. – 29. novembra' );
        $this->xn_show_control( 'addr_show', 'Zobraziť adresu' );
        $this->add_control('addr_name', [
            'label'     => 'Názov miesta',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Mestská športová hala Nitra',
            'condition' => [ 'addr_show' => 'yes' ],
        ]);
        $this->add_control('addr_street', [
            'label'     => 'Adresa',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Dolnočermánska 105, 949 01 Nitra – Klokočina',
            'condition' => [ 'addr_show' => 'yes' ],
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_info', [
            'label' => 'Info bunky',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'info_show', 'Zobraziť info bunky' );
        $cell = new \Elementor\Repeater();
        $cell->add_control('label', [
            'label'   => 'Nadpis',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $cell->add_control('text', [
            'label'   => 'Text',
            'type'    => \Elementor\Controls_Manager::WYSIWYG,
            'default' => '',
        ]);
        $this->add_control('info', [
            'label'       => 'Bunky',
            'type'        => \Elementor\Controls_Manager::REPEATER,
            'fields'      => $cell->get_controls(),
            'title_field' => '{{{ label }}}',
            'condition'   => [ 'info_show' => 'yes' ],
            'default'     => [
                [ 'label' => 'Doprava',          'text' => '<p>Autom z Bratislavy do 60 minút, z Viedne do 90 minút. Hala stojí na sídlisku Klokočina, pár minút od centra Nitry.</p>' ],
                [ 'label' => 'Parkovanie',       'text' => '<p>Parkovanie priamo pri hale na Dolnočermánskej ulici.</p>' ],
                [ 'label' => 'Vstup do haly',    'text' => '<p>Otvorenie hodinu pred prvým blokom. E-vstupenku s QR kódom stačí ukázať na mobile.</p>' ],
                [ 'label' => 'Pre návštevníkov', 'text' => '<p>Vstupenka platí na celý deň — medzi blokmi môžeš odísť a vrátiť sa. Predvianočná Nitra je pár minút od haly.</p>' ],
                [ 'label' => 'Hala',             'text' => '<p>Jedna súťažná a dve tréningové plochy, výška stropu 15 m — domáce prostredie Christmas Nitra.</p>' ],
                [ 'label' => 'Ubytovanie',       'text' => '<p>Partnerské hotely s kódom „Christmas Nitra 2026“: H11, Centrum, City, OKO a Zobor.</p>' ],
            ],
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_buttons', [
            'label' => 'Tlačidlá',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_button_controls( 'btn1', 'Tlačidlo 1', 'Kúpiť vstupenku', 'primary', '', $this->xn_tickets_desc() );
        $this->xn_button_controls( 'btn2', 'Tlačidlo 2', 'Otvoriť v mapách', 'grad', 'https://www.google.com/maps/search/?api=1&query=Dolno%C4%8Derm%C3%A1nska+105%2C+949+01+Nitra' );
        $this->end_controls_section();

        $this->start_controls_section( 'section_photo', [
            'label' => 'Fotka',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'photo_show', 'Zobraziť fotku' );
        $this->xn_media_controls( 'photo', 'Fotka haly', xn_asset( 'photo/venue-hall.jpg' ), '{{WRAPPER}} .venue__photo', 'Mestská športová hala Nitra – Klokočina' );
        $this->add_responsive_control('photo_height', [
            'label'      => 'Výška fotky',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 160, 'max' => 800 ] ],
            'selectors'  => [ '{{WRAPPER}} .venue__photo' => 'height: {{SIZE}}{{UNIT}};' ],
        ]);
        $this->end_controls_section();

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'eyebrow',    'Eyebrow',          '{{WRAPPER}} .xn-eyebrow' ],
            [ 'title',      'Nadpis sekcie',    '{{WRAPPER}} .xn-h2' ],
            [ 'addr_name',  'Názov miesta',     '{{WRAPPER}} .venue__addr b' ],
            [ 'addr',       'Adresa',           '{{WRAPPER}} .venue__addr span' ],
            [ 'info_label', 'Bunka — nadpis',   '{{WRAPPER}} .venue__info-label' ],
            [ 'info_text',  'Bunka — text',     '{{WRAPPER}} .venue__info-text' ],
        ]);
        $this->xn_style_cards([
            [ 'photo', 'Fotka', '{{WRAPPER}} .venue__photo' ],
        ]);
        $this->xn_style_buttons();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
