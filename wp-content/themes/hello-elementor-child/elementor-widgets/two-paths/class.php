<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_TwoPaths extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'two-paths'; }
    public function get_title()      { return 'CN · Dve cesty'; }
    public function get_icon()       { return 'eicon-column'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'two-paths-script' ]; }
    public function get_style_depends()  { return [ 'two-paths-style' ]; }

    protected function get_card_defaults() {
        return [
            'c1' => [
                'label'    => 'Karta 1 — naživo (svetlá)',
                'tag'      => 'Naživo v Nitre',
                'title'    => 'Buď pri tom osobne',
                'text'     => '<p>Štyri dni modernej gymnastiky, medzinárodné štartové pole a predvianočná Nitra. Vstupenka na deň alebo na celú súťaž.</p>',
                'features' => "Vstup na všetky súťažné bloky daného dňa\nSobotné finále a vyhlásenie výsledkov\nVstupenka na 1 deň, 2 dni alebo 4-dňový pass",
                'btn'      => 'Kúpiť vstupenku',
                'foot'     => 'tickets.sgf.sk · e-vstupenka s QR kódom',
            ],
            'c2' => [
                'label'    => 'Karta 2 — livestream (tmavá)',
                'tag'      => 'Livestream',
                'title'    => 'Sleduj odkiaľkoľvek',
                'text'     => '<p>Celá súťaž naživo v HD kvalite. Prístup si kúpiš na tickets.sgf.sk, pozeráš na stream.sgf.sk — na mobile, notebooku aj TV.</p>',
                'features' => "Prenos všetkých štyroch dní vrátane finále\nPrístup na 1 deň, 2 dni alebo 4-dňový pass\nJeden prístup, všetky zariadenia — mobil, notebook, TV",
                'btn'      => 'Kúpiť online prístup',
                'foot'     => 'stream.sgf.sk · prístup ihneď po zaplatení',
            ],
        ];
    }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Hlavička sekcie',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_anchor_control( 'cesty' );
        $this->xn_intro_controls(
            'Vyber si svoj zážitok',
            'Dve cesty k Christmas Nitra',
            '<p>Príď do haly a zaži atmosféru finále na vlastnej koži — alebo si zapni livestream a sleduj každú zostavu z pohodlia domova.</p>'
        );
        $this->end_controls_section();

        foreach ( $this->get_card_defaults() as $c => $d ) {
            $this->start_controls_section( 'section_' . $c, [
                'label' => $d['label'],
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ]);
            $this->xn_show_control( $c . '_show', 'Zobraziť kartu' );
            $this->add_control( $c . '_tag', [
                'label'   => 'Štítok',
                'type'    => \Elementor\Controls_Manager::TEXT,
                'default' => $d['tag'],
            ]);
            $this->add_control( $c . '_title', [
                'label'   => 'Nadpis',
                'type'    => \Elementor\Controls_Manager::TEXT,
                'default' => $d['title'],
            ]);
            $this->add_control( $c . '_text', [
                'label'   => 'Text',
                'type'    => \Elementor\Controls_Manager::WYSIWYG,
                'default' => $d['text'],
            ]);
            $this->add_control( $c . '_features', [
                'label'       => 'Body zoznamu (1 riadok = 1 bod)',
                'type'        => \Elementor\Controls_Manager::TEXTAREA,
                'rows'        => 4,
                'default'     => $d['features'],
            ]);

            $this->xn_heading_control( $c . '_price_heading', 'Cena' );
            $this->xn_show_control( $c . '_price_show', 'Zobraziť cenu' );
            $this->add_control( $c . '_price_from', [
                'label'     => 'Pred cenou',
                'type'      => \Elementor\Controls_Manager::TEXT,
                'default'   => 'od',
                'condition' => [ $c . '_price_show' => 'yes' ],
            ]);
            $this->add_control( $c . '_price', [
                'label'     => 'Cena',
                'type'      => \Elementor\Controls_Manager::TEXT,
                'default'   => '6,80 €',
                'condition' => [ $c . '_price_show' => 'yes' ],
            ]);
            $this->add_control( $c . '_price_unit', [
                'label'     => 'Za cenou',
                'type'      => \Elementor\Controls_Manager::TEXT,
                'default'   => '/ 1 deň',
                'condition' => [ $c . '_price_show' => 'yes' ],
            ]);
            $this->xn_show_control( $c . '_eb_show', 'Zobraziť Early Bird štítok' );
            $this->add_control( $c . '_eb_label', [
                'label'     => 'Early Bird — text',
                'type'      => \Elementor\Controls_Manager::TEXT,
                'default'   => 'Early Bird',
                'condition' => [ $c . '_eb_show' => 'yes' ],
            ]);
            $this->add_control( $c . '_eb_date', [
                'label'     => 'Early Bird — dátum',
                'type'      => \Elementor\Controls_Manager::TEXT,
                'default'   => 'do 31. 10.',
                'condition' => [ $c . '_eb_show' => 'yes' ],
            ]);

            $this->xn_button_controls( $c . '_btn', 'Tlačidlo', $d['btn'], 'primary', '', $this->xn_tickets_desc() );
            $this->add_control( $c . '_foot', [
                'label'   => 'Poznámka pod tlačidlom',
                'type'    => \Elementor\Controls_Manager::TEXT,
                'default' => $d['foot'],
            ]);
            $this->end_controls_section();
        }

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'eyebrow',    'Eyebrow',          '{{WRAPPER}} .xn-eyebrow' ],
            [ 'title',      'Nadpis sekcie',    '{{WRAPPER}} .xn-h2' ],
            [ 'lead',       'Úvodný text',      '{{WRAPPER}} .xn-lead' ],
            [ 'card_tag',   'Karta — štítok',   '{{WRAPPER}} .two-paths__tag' ],
            [ 'card_title', 'Karta — nadpis',   '{{WRAPPER}} .two-paths__title' ],
            [ 'card_text',  'Karta — text',     '{{WRAPPER}} .two-paths__text' ],
            [ 'card_list',  'Karta — zoznam',   '{{WRAPPER}} .two-paths__list' ],
            [ 'card_price', 'Karta — cena',     '{{WRAPPER}} .two-paths__price b' ],
        ]);
        $this->xn_style_cards([
            [ 'card_light', 'Karta 1 (svetlá)', '{{WRAPPER}} .two-paths__card--light' ],
            [ 'card_dark',  'Karta 2 (tmavá)',  '{{WRAPPER}} .two-paths__card--dark' ],
        ]);
        $this->xn_style_buttons();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
