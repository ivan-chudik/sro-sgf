<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_Header extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'header'; }
    public function get_title()      { return 'CN · Header'; }
    public function get_icon()       { return 'eicon-header'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'header-script' ]; }
    public function get_style_depends()  { return [ 'header-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_brand', [
            'label' => 'Logo',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_media_controls( 'logo', 'Logo', xn_asset( 'logos/sgf-white.png' ), '{{WRAPPER}} .header__logo', 'SGF' );
        $this->add_control('brand_text', [
            'label'   => 'Text vedľa loga',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Christmas Nitra 2026',
        ]);
        $this->add_control('brand_url', [
            'label'       => 'Odkaz loga',
            'type'        => \Elementor\Controls_Manager::URL,
            'placeholder' => 'prázdne = úvodná stránka jazyka',
            'description' => 'Prázdne = domovská stránka aktívneho jazyka (Polylang).',
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_nav', [
            'label' => 'Menu',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'nav_show', 'Zobraziť menu' );
        $this->add_control('nav_template', [
            'label'       => 'Šablóna s Nav Menu',
            'type'        => \Elementor\Controls_Manager::SELECT,
            'options'     => xn_elementor_templates(),
            'default'     => '',
            'description' => 'Uložená Elementor šablóna (Container) s natívnym Nav Menu widgetom — samostatná pre SK a EN. Mobilný hamburger rieši Nav Menu.',
            'condition'   => [ 'nav_show' => 'yes' ],
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_lang', [
            'label' => 'Prepínač jazyka',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'lang_show', 'Zobraziť prepínač jazyka' );
        $this->add_control('lang_active', [
            'label'     => 'Aktívny jazyk',
            'type'      => \Elementor\Controls_Manager::SELECT,
            'default'   => 'auto',
            'options'   => [ 'auto' => 'Automaticky (Polylang)', 'sk' => 'SK', 'en' => 'EN' ],
            'condition' => [ 'lang_show' => 'yes' ],
        ]);
        $this->add_control('lang_sk_label', [
            'label'     => 'SK — text',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'SK',
            'condition' => [ 'lang_show' => 'yes' ],
        ]);
        $this->add_control('lang_sk_url', [
            'label'     => 'SK — odkaz',
            'description' => 'Používa sa len bez Polylangu. S Polylangom prepínač vedie na jazykové dvojča aktuálnej stránky.',
            'type'      => \Elementor\Controls_Manager::URL,
            'default'   => [ 'url' => XN_HOME_SK ],
            'condition' => [ 'lang_show' => 'yes' ],
        ]);
        $this->add_control('lang_en_label', [
            'label'     => 'EN — text',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'EN',
            'condition' => [ 'lang_show' => 'yes' ],
        ]);
        $this->add_control('lang_en_url', [
            'label'     => 'EN — odkaz',
            'description' => 'Používa sa len bez Polylangu. S Polylangom prepínač vedie na jazykové dvojča aktuálnej stránky.',
            'type'      => \Elementor\Controls_Manager::URL,
            'default'   => [ 'url' => XN_HOME_EN ],
            'condition' => [ 'lang_show' => 'yes' ],
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_cta', [
            'label' => 'Tlačidlo',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_button_controls( 'cta', 'Kúpiť vstupenku', 'Kúpiť vstupenku', 'primary', '', $this->xn_tickets_desc() );
        $this->end_controls_section();

        $this->xn_style_section( '{{WRAPPER}} .header__bar', 'Lišta' );
        $this->xn_style_texts([
            [ 'brand', 'Text vedľa loga', '{{WRAPPER}} .header__brand-text' ],
            [ 'lang',  'Prepínač jazyka', '{{WRAPPER}} .header__lang a' ],
        ]);
        $this->xn_style_buttons( '{{WRAPPER}} .header__cta' );
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
