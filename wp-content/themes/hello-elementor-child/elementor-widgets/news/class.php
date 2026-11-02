<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_News extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'news'; }
    public function get_title()      { return 'CN · Novinky'; }
    public function get_icon()       { return 'eicon-post-slider'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'news-script' ]; }
    public function get_style_depends()  { return [ 'news-style' ]; }

    private function category_options() {
        $out   = [];
        $terms = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false ] );
        if ( is_wp_error( $terms ) ) return $out;
        foreach ( $terms as $t ) {
            $out[ $t->term_id ] = $t->name;
        }
        return $out;
    }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Hlavička sekcie',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_anchor_control( 'novinky' );
        $this->xn_intro_controls( 'Novinky', 'Čo sa deje okolo Christmas Nitra', '' );
        $this->end_controls_section();

        $this->start_controls_section( 'section_query', [
            'label' => 'Články',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->add_control('posts_count', [
            'label'   => 'Počet článkov',
            'type'    => \Elementor\Controls_Manager::NUMBER,
            'min'     => 1,
            'max'     => 24,
            'default' => 9,
        ]);
        $this->add_control('categories', [
            'label'       => 'Kategórie',
            'type'        => \Elementor\Controls_Manager::SELECT2,
            'multiple'    => true,
            'label_block' => true,
            'options'     => $this->category_options(),
            'description' => 'Prázdne = všetky články. Jazyk sa berie automaticky podľa stránky (Polylang).',
        ]);
        $this->add_control('empty_hide', [
            'label'        => 'Skryť sekciu, keď nie sú články',
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'label_on'     => 'Áno',
            'label_off'    => 'Nie',
            'return_value' => 'yes',
            'default'      => 'yes',
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_card', [
            'label' => 'Karta článku',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'image_show', 'Zobraziť obrázok' );
        $this->xn_show_control( 'cat_show', 'Zobraziť kategóriu' );
        $this->xn_show_control( 'date_show', 'Zobraziť dátum' );
        $this->add_control('date_format', [
            'label'     => 'Formát dátumu',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'j. n. Y',
            'condition' => [ 'date_show' => 'yes' ],
        ]);
        $this->xn_show_control( 'excerpt_show', 'Zobraziť perex' );
        $this->add_control('excerpt_words', [
            'label'     => 'Perex — počet slov',
            'type'      => \Elementor\Controls_Manager::NUMBER,
            'min'       => 5,
            'max'       => 80,
            'default'   => 22,
            'condition' => [ 'excerpt_show' => 'yes' ],
        ]);
        $this->xn_show_control( 'more_show', 'Zobraziť „Čítať“' );
        $this->add_control('more_text', [
            'label'     => 'Text odkazu',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Čítať článok',
            'condition' => [ 'more_show' => 'yes' ],
        ]);
        $this->add_control('link_note', [
            'type'            => \Elementor\Controls_Manager::RAW_HTML,
            'raw'             => 'Karta vedie na <b>pôvodný článok</b> (pole „Pôvodný článok“ pri článku vo WordPresse). Ak je prázdne, vedie na článok na tomto webe.',
            'content_classes' => 'elementor-descriptor',
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_nav', [
            'label' => 'Slider',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->add_responsive_control('per_view', [
            'label'       => 'Počet kariet naraz',
            'type'        => \Elementor\Controls_Manager::SLIDER,
            'range'       => [ 'px' => [ 'min' => 1, 'max' => 5, 'step' => 0.05 ] ],
            'description' => 'Prázdne = 3 / 2 / 1,15 (desktop / tablet / mobil). Desatinné číslo = vidno kúsok ďalšej karty.',
            'selectors'   => [ '{{WRAPPER}} .news__track' => '--news-per-view: {{SIZE}};' ],
        ]);
        $this->xn_show_control( 'arrows_show', 'Zobraziť šípky' );
        $this->add_control('prev_label', [
            'label'     => 'Šípka vľavo (pre čítačky)',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Predchádzajúce články',
            'condition' => [ 'arrows_show' => 'yes' ],
        ]);
        $this->add_control('next_label', [
            'label'     => 'Šípka vpravo (pre čítačky)',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Ďalšie články',
            'condition' => [ 'arrows_show' => 'yes' ],
        ]);
        $this->xn_button_controls( 'btn1', 'Tlačidlo pod sliderom', 'Všetky novinky na sgf.sk', 'grad', 'https://www.sgf.sk/sk/articles/aktuality' );
        $this->end_controls_section();

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'eyebrow',    'Eyebrow',           '{{WRAPPER}} .xn-eyebrow' ],
            [ 'title',      'Nadpis sekcie',     '{{WRAPPER}} .xn-h2' ],
            [ 'lead',       'Úvodný text',       '{{WRAPPER}} .xn-lead' ],
            [ 'card_cat',   'Karta — kategória', '{{WRAPPER}} .news__cat' ],
            [ 'card_date',  'Karta — dátum',     '{{WRAPPER}} .news__date' ],
            [ 'card_title', 'Karta — nadpis',    '{{WRAPPER}} .news__title' ],
            [ 'card_text',  'Karta — perex',     '{{WRAPPER}} .news__excerpt' ],
            [ 'card_more',  'Karta — Čítať',     '{{WRAPPER}} .news__more' ],
        ]);
        $this->xn_style_cards([
            [ 'card',  'Karta článku',  '{{WRAPPER}} .news__card' ],
            [ 'arrow', 'Šípky',         '{{WRAPPER}} .news__arrow' ],
        ]);

        $this->start_controls_section( 'style_media', [
            'label' => 'Obrázok a šípky',
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ]);
        $this->add_responsive_control('image_height', [
            'label'      => 'Výška obrázka',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 120, 'max' => 480 ] ],
            'selectors'  => [ '{{WRAPPER}} .news__media' => 'aspect-ratio: auto; height: {{SIZE}}{{UNIT}};' ],
        ]);
        $this->add_control('cat_bg', [
            'label'     => 'Kategória — pozadie',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .news__cat' => 'background: {{VALUE}};' ],
        ]);
        $this->add_control('arrow_color', [
            'label'     => 'Šípky — farba ikony',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .news__arrow' => 'color: {{VALUE}};' ],
        ]);
        $this->end_controls_section();

        $this->xn_style_buttons();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
