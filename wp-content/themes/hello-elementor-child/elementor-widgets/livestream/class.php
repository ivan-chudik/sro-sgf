<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_Livestream extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'livestream'; }
    public function get_title()      { return 'CN · Livestream'; }
    public function get_icon()       { return 'eicon-video-camera'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'livestream-script' ]; }
    public function get_style_depends()  { return [ 'livestream-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Obsah',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_anchor_control( 'livestream' );
        $this->xn_intro_controls(
            'Livestream · stream.sgf.sk',
            'Nemôžeš prísť? Nič nezmeškáš.',
            '<p>Všetky štyri dni odvysielame naživo. Prístup si kúpiš rovnako ako vstupenku a pozeráš na ľubovoľnom zariadení.</p>'
        );
        $this->xn_show_control( 'steps_show', 'Zobraziť kroky' );
        $step = new \Elementor\Repeater();
        $step->add_control('title', [
            'label'   => 'Nadpis kroku',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $step->add_control('text', [
            'label'   => 'Text',
            'type'    => \Elementor\Controls_Manager::TEXTAREA,
            'rows'    => 2,
            'default' => '',
        ]);
        $this->add_control('steps', [
            'label'       => 'Kroky',
            'type'        => \Elementor\Controls_Manager::REPEATER,
            'fields'      => $step->get_controls(),
            'title_field' => '{{{ title }}}',
            'condition'   => [ 'steps_show' => 'yes' ],
            'default'     => [
                [ 'title' => 'Kúp online prístup',           'text' => 'na tickets.sgf.sk — na jeden deň alebo na celú súťaž.' ],
                [ 'title' => 'Prihlás sa na stream.sgf.sk',  'text' => 'rovnakým e-mailom, akým si nakupoval.' ],
                [ 'title' => 'Sleduj naživo',                'text' => 'všetky súťažné bloky daného dňa — na mobile, notebooku alebo smart TV.' ],
            ],
        ]);
        $this->xn_button_controls( 'btn1', 'Tlačidlo 1', 'Kúpiť online prístup', 'primary', '', $this->xn_tickets_desc() );
        $this->xn_button_controls( 'btn2', 'Tlačidlo 2', 'stream.sgf.sk', 'grad', 'https://stream.sgf.sk/' );
        $this->end_controls_section();

        $this->start_controls_section( 'section_mock', [
            'label' => 'Mockup notebook + mobil',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'mock_show', 'Zobraziť mockup' );
        $this->add_control('mock_label', [
            'label'   => 'Popis pre čítačky (aria-label)',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Ukážka livestreamu na notebooku a mobile',
        ]);
        $this->add_control('bar_url', [
            'label'   => 'Adresa v lište prehliadača',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'stream.sgf.sk',
        ]);
        $this->add_control('bar_quality', [
            'label'   => 'Kvalita (vpravo v lište)',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'HD',
        ]);
        $this->add_control('live_label', [
            'label'   => 'Štítok Live',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Live',
        ]);

        $this->xn_heading_control( 'lap_heading', 'Notebook' );
        $this->xn_media_controls( 'lap_image', 'Obrázok', xn_asset( 'photo/moment-ribbon.jpg' ), '{{WRAPPER}} .livestream__lap .livestream__scr img' );
        $this->add_control('lap_title', [
            'label'   => 'Titulok',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Christmas Nitra 2026 · Finále',
        ]);
        $this->add_control('lap_sub', [
            'label'   => 'Podtitulok',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Stuha · Sobota 28. 11. · Mestská športová hala Nitra',
        ]);

        $this->xn_heading_control( 'ph_heading', 'Mobil' );
        $this->xn_media_controls( 'ph_image', 'Obrázok', xn_asset( 'photo/moment-hoops-2024.jpg' ), '{{WRAPPER}} .livestream__ph .livestream__scr img' );
        $this->add_control('ph_title', [
            'label'   => 'Titulok',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Spoločné skladby',
        ]);
        $this->add_control('ph_sub', [
            'label'   => 'Podtitulok',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Nedeľa 29. 11. · MSR',
        ]);
        $this->end_controls_section();

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'eyebrow',    'Eyebrow',        '{{WRAPPER}} .xn-eyebrow' ],
            [ 'title',      'Nadpis sekcie',  '{{WRAPPER}} .xn-h2' ],
            [ 'lead',       'Úvodný text',    '{{WRAPPER}} .xn-lead' ],
            [ 'step_num',   'Krok — číslo',   '{{WRAPPER}} .livestream__steps li::before', 'none' ],
            [ 'step_title', 'Krok — nadpis',  '{{WRAPPER}} .livestream__steps li b' ],
            [ 'step_text',  'Krok — text',    '{{WRAPPER}} .livestream__steps li' ],
        ]);
        $this->xn_style_cards([
            [ 'device', 'Rám zariadení', '{{WRAPPER}} .livestream__lid, {{WRAPPER}} .livestream__ph' ],
        ]);
        $this->xn_style_buttons();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
