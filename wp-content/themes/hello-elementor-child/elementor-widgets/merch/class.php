<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Elementor\Controls_Manager;
use Elementor\Repeater;

class Elementor_Widget_Merch extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    const FORM_URL = 'https://docs.google.com/forms/d/e/1FAIpQLSdGBTYevODJsGmEQ5ZfteUGZm8jswAmTqlfs7jPS8IZHhbCA/viewform';
    const IMG_WIDE = 'https://sro.sgf.sk/wp-content/uploads/2026/11/Merch_CHN_plagat-sirka-scaled.jpg';
    const IMG_TALL = 'https://sro.sgf.sk/wp-content/uploads/2026/11/Merch_CHN_pagat-vyska-scaled.jpg';

    public function get_name()       { return 'merch'; }
    public function get_title()      { return 'CN · Merch'; }
    public function get_icon()       { return 'eicon-cart'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'merch-script' ]; }
    public function get_style_depends()  { return [ 'merch-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Hlavička sekcie',
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_anchor_control( 'merch' );
        $this->xn_intro_controls(
            'Oficiálna kolekcia · CHN 2026',
            'Merch Christmas Nitra 2026',
            '<p>Tričko, taška, nákrčník, plyšový macko, kľúčenka s mackom aj šnúrka na krk. Objednaj si svoj kúsok z oficiálnej kolekcie vopred.</p>'
        );
        $this->xn_show_control( 'badge_show', 'Zobraziť uzávierku' );
        $this->add_control('badge', [
            'label'     => 'Text uzávierky',
            'type'      => Controls_Manager::TEXT,
            'default'   => 'Predobjednávky do 15. 11. 2026',
            'condition' => [ 'badge_show' => 'yes' ],
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_poster', [
            'label' => 'Plagát',
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'poster_show', 'Zobraziť plagát' );
        $this->add_control('poster', [
            'label'     => 'Plagát — desktop / tablet (na šírku)',
            'type'      => Controls_Manager::MEDIA,
            'default'   => [ 'url' => self::IMG_WIDE ],
            'condition' => [ 'poster_show' => 'yes' ],
        ]);
        $this->add_responsive_control('poster_width', [
            'label'      => 'Šírka plagátu (desktop / tablet)',
            'type'       => Controls_Manager::SLIDER,
            'size_units' => [ 'px', '%' ],
            'range'      => [ 'px' => [ 'min' => 200, 'max' => 1240 ], '%' => [ 'min' => 20, 'max' => 100 ] ],
            'selectors'  => [ '{{WRAPPER}} .merch__poster' => '--merch-w: {{SIZE}}{{UNIT}};' ],
            'condition'  => [ 'poster_show' => 'yes' ],
        ]);
        $this->add_control('poster_mobile', [
            'label'       => 'Plagát — mobil (na výšku)',
            'type'        => Controls_Manager::MEDIA,
            'default'     => [ 'url' => self::IMG_TALL ],
            'description' => 'Zobrazí sa do 767 px. Prázdne = plagát na šírku aj na mobile.',
            'condition'   => [ 'poster_show' => 'yes' ],
        ]);
        $this->add_responsive_control('poster_mobile_width', [
            'label'      => 'Šírka plagátu (mobil)',
            'type'       => Controls_Manager::SLIDER,
            'size_units' => [ 'px', '%' ],
            'range'      => [ 'px' => [ 'min' => 160, 'max' => 767 ], '%' => [ 'min' => 20, 'max' => 100 ] ],
            'selectors'  => [ '{{WRAPPER}} .merch__poster' => '--merch-w-m: {{SIZE}}{{UNIT}};' ],
            'condition'  => [ 'poster_show' => 'yes' ],
        ]);
        $this->add_control('poster_alt', [
            'label'     => 'Alt text',
            'type'      => Controls_Manager::TEXT,
            'default'   => 'Merch Christmas Nitra 2026 — tričko, taška, nákrčník, plyšový macko, kľúčenka a šnúrka na krk',
            'condition' => [ 'poster_show' => 'yes' ],
        ]);
        $this->add_control('poster_lightbox', [
            'label'        => 'Kliknutím zväčšiť (lightbox)',
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => 'Áno',
            'label_off'    => 'Nie',
            'return_value' => 'yes',
            'default'      => 'yes',
            'condition'    => [ 'poster_show' => 'yes' ],
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_items', [
            'label' => 'Produkty',
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'items_show', 'Zobraziť zoznam produktov' );
        $repeater = new Repeater();
        $repeater->add_control('text', [
            'label'   => 'Produkt',
            'type'    => Controls_Manager::TEXT,
            'default' => 'Produkt',
        ]);
        $this->add_control('items', [
            'label'       => 'Produkty',
            'type'        => Controls_Manager::REPEATER,
            'fields'      => $repeater->get_controls(),
            'title_field' => '{{{ text }}}',
            'default'     => array_map( function( $t ) { return [ 'text' => $t ]; }, [
                'Tričko', 'Taška', 'Nákrčník', 'Plyšový macko', 'Kľúčenka s mackom', 'Šnúrka na krk',
            ] ),
            'condition'   => [ 'items_show' => 'yes' ],
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_cta', [
            'label' => 'Tlačidlo a poznámka',
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_button_controls( 'btn1', 'Tlačidlo — objednávka', 'Objednať merch', 'primary', self::FORM_URL );
        $this->xn_heading_control( 'note_heading', 'Poznámka pod tlačidlom' );
        $this->xn_show_control( 'note_show', 'Zobraziť poznámku' );
        $this->add_control('note', [
            'label'     => 'Poznámka',
            'type'      => Controls_Manager::WYSIWYG,
            'default'   => '<p>Podľa záujmu budeme niektoré kúsky predávať aj priamo na podujatí. Viac info čoskoro.</p>',
            'condition' => [ 'note_show' => 'yes' ],
        ]);
        $this->end_controls_section();

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'eyebrow', 'Eyebrow',         '{{WRAPPER}} .xn-eyebrow' ],
            [ 'title',   'Nadpis sekcie',   '{{WRAPPER}} .xn-h2' ],
            [ 'lead',    'Úvodný text',     '{{WRAPPER}} .xn-lead' ],
            [ 'badge',   'Uzávierka',       '{{WRAPPER}} .merch__badge' ],
            [ 'item',    'Produkty',        '{{WRAPPER}} .merch__item' ],
            [ 'note',    'Poznámka',        '{{WRAPPER}} .merch__note' ],
        ]);
        $this->xn_style_cards([
            [ 'poster', 'Plagát',          '{{WRAPPER}} .merch__poster' ],
            [ 'badge',  'Uzávierka — box', '{{WRAPPER}} .merch__badge' ],
            [ 'item',   'Produkt — box',   '{{WRAPPER}} .merch__item' ],
        ]);

        $this->start_controls_section( 'style_icons', [
            'label' => 'Ikonky',
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('badge_dot_color', [
            'label'     => 'Bodka pri uzávierke',
            'type'      => Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .merch__badge::before' => 'background: {{VALUE}};' ],
        ]);
        $this->add_responsive_control('badge_dot_size', [
            'label'      => 'Veľkosť bodky',
            'type'       => Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 4, 'max' => 24 ] ],
            'selectors'  => [ '{{WRAPPER}} .merch__badge::before' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ],
        ]);
        $this->end_controls_section();

        $this->xn_style_buttons();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
