<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_NotFound extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'not-found'; }
    public function get_title()      { return 'CN · 404 stránka'; }
    public function get_icon()       { return 'eicon-error-404'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'not-found-script' ]; }
    public function get_style_depends()  { return [ 'not-found-style' ]; }

    protected function get_lang_defaults() {
        return [
            'sk' => [
                'label' => 'Slovenčina',
                'title' => 'Táto stránka neexistuje',
                'text'  => '<p>Odkaz je nesprávny alebo stránka bola presunutá. Všetko o Christmas Nitra 2026 nájdeš na úvodnej stránke.</p>',
                'btn1'  => 'Späť na úvod',
                'btn2'  => 'Kúpiť vstupenku',
            ],
            'en' => [
                'label' => 'English',
                'title' => 'This page doesn’t exist',
                'text'  => '<p>The link is wrong or the page has moved. Everything about Christmas Nitra 2026 is on the home page.</p>',
                'btn1'  => 'Back to home',
                'btn2'  => 'Buy tickets',
            ],
        ];
    }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Spoločné',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->add_control('info', [
            'type'            => \Elementor\Controls_Manager::RAW_HTML,
            'raw'             => '404 šablóna je jedna pre oba jazyky — widget zobrazí SK alebo EN texty podľa jazyka URL (Polylang).',
            'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
        ]);
        $this->add_control('preview_lang', [
            'label'   => 'Náhľad v editore',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'sk',
            'options' => [ 'sk' => 'SK', 'en' => 'EN' ],
        ]);
        $this->xn_show_control( 'logo_show', 'Zobraziť logo' );
        $this->xn_media_controls( 'logo', 'Logo', xn_asset( 'logos/sgf-white.png' ), '{{WRAPPER}} .not-found__logo', 'SGF' );
        $this->add_control('code', [
            'label'   => 'Veľké číslo',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '404',
        ]);
        $this->xn_show_control( 'btn2_show', 'Zobraziť tlačidlo na predaj' );
        $this->xn_show_control( 'ribbons_show', 'Zobraziť dekoratívne stuhy' );
        $this->end_controls_section();

        foreach ( $this->get_lang_defaults() as $l => $d ) {
            $this->start_controls_section( 'section_' . $l, [
                'label' => 'Texty — ' . $d['label'],
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ]);
            $this->add_control( $l . '_title', [
                'label'   => 'Nadpis',
                'type'    => \Elementor\Controls_Manager::TEXT,
                'default' => $d['title'],
            ]);
            $this->add_control( $l . '_text', [
                'label'   => 'Text',
                'type'    => \Elementor\Controls_Manager::WYSIWYG,
                'default' => $d['text'],
            ]);
            $this->add_control( $l . '_btn1', [
                'label'   => 'Tlačidlo — úvod',
                'type'    => \Elementor\Controls_Manager::TEXT,
                'default' => $d['btn1'],
            ]);
            $this->add_control( $l . '_btn2', [
                'label'   => 'Tlačidlo — predaj',
                'type'    => \Elementor\Controls_Manager::TEXT,
                'default' => $d['btn2'],
            ]);
            $this->end_controls_section();
        }

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'code',  'Číslo 404', '{{WRAPPER}} .not-found__code', 'gradient' ],
            [ 'title', 'Nadpis',    '{{WRAPPER}} .not-found__title' ],
            [ 'text',  'Text',      '{{WRAPPER}} .not-found__text' ],
        ]);
        $this->xn_style_buttons();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
