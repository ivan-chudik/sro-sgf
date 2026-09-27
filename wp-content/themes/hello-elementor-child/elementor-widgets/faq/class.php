<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_Faq extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'faq'; }
    public function get_title()      { return 'CN · FAQ'; }
    public function get_icon()       { return 'eicon-accordion'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'faq-script' ]; }
    public function get_style_depends()  { return [ 'faq-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Hlavička sekcie',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_anchor_control( 'faq' );
        $this->xn_intro_controls( 'Časté otázky', 'Než si kúpiš vstupenku' );
        $this->end_controls_section();

        $this->start_controls_section( 'section_items', [
            'label' => 'Otázky',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $repeater = new \Elementor\Repeater();
        $repeater->add_control('question', [
            'label'   => 'Otázka',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $repeater->add_control('answer', [
            'label'   => 'Odpoveď',
            'type'    => \Elementor\Controls_Manager::WYSIWYG,
            'default' => '',
        ]);
        $repeater->add_control('open', [
            'label'        => 'Otvorená pri načítaní',
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'return_value' => 'yes',
        ]);
        $this->add_control('items', [
            'label'       => 'Otázky',
            'type'        => \Elementor\Controls_Manager::REPEATER,
            'fields'      => $repeater->get_controls(),
            'title_field' => '{{{ question }}}',
            'default'     => [
                [ 'open' => 'yes', 'question' => 'Kde sa súťaž koná a ako sa tam dostanem?', 'answer' => '<p>V Mestskej športovej hale v Nitre — Dolnočermánska 105, Nitra – Klokočina — od štvrtka 26. do nedele 29. novembra 2026. Z Bratislavy je to autom do hodiny, z Viedne do 90 minút; parkovať sa dá priamo pri hale.</p>' ],
                [ 'question' => 'Kedy je finále?', 'answer' => '<p>Finále WG súťaže na náčiní (obruč, lopta, kužele, stuha) je v sobotu 28. 11. spolu s vyhlásením výsledkov. Víkend patrí aj majstrovstvám Slovenska spoločných skladieb, nedeľa spoločným skladbám, dvojiciam a trojiciam súťaže Open.</p>' ],
                [ 'question' => 'Platí vstupenka na celý deň?', 'answer' => '<p>Áno — denná vstupenka platí na všetky súťažné bloky daného dňa, môžeš odísť a vrátiť sa. Dvojdňová vstupenka a 4-dňový pass platia rovnako pre každý zo zvolených dní.</p>' ],
                [ 'question' => 'Čo znamená Early Bird?', 'answer' => '<p>Zvýhodnená cena pri nákupe do 31. 10. 2026 — platí rovnako pre vstupenky do haly aj pre livestream. Od 1. 11. platí plná cena. Ceny sú konečné, nič sa k nim nepripočítava.</p>' ],
                [ 'question' => 'Ako funguje livestream?', 'answer' => '<p>Prístup kúpiš na tickets.sgf.sk, pozeráš na stream.sgf.sk po prihlásení rovnakým e-mailom. Funguje na mobile, notebooku aj smart TV. Prístup je aktívny ihneď po zaplatení.</p>' ],
                [ 'question' => 'Môžem vstupenku vrátiť alebo preniesť na iný deň?', 'answer' => '<p>Vstupenky sú viazané na konkrétny deň alebo dni. Podmienky vrátenia a výmeny sa riadia obchodnými podmienkami tickets.sgf.sk — nájdeš ich pri nákupe.</p>' ],
                [ 'question' => 'Kto súťaž organizuje?', 'answer' => '<p>ŠK ŠOG Nitra – moderná gymnastika (MODERGYM) v spolupráci so Slovenskou gymnastickou federáciou a Strednou športovou školou Nitra. Podujatie je oficiálnou súťažou World Gymnastics. Kontakt: Zuzana Vilčeková, <a href="mailto:christmas.nitra@gmail.com">christmas.nitra@gmail.com</a>, +421 911 430 001.</p>' ],
            ],
        ]);
        $this->end_controls_section();

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'eyebrow',  'Eyebrow',        '{{WRAPPER}} .xn-eyebrow' ],
            [ 'title',    'Nadpis sekcie',  '{{WRAPPER}} .xn-h2' ],
            [ 'question', 'Otázka',         '{{WRAPPER}} .faq__item summary' ],
            [ 'answer',   'Odpoveď',        '{{WRAPPER}} .faq__answer' ],
        ]);
        $this->start_controls_section('style_accordion', [
            'label' => 'Akordeón',
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('icon_color', [
            'label'     => 'Farba ikonky +/–',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .faq__item summary::after' => 'color: {{VALUE}};' ],
        ]);
        $this->add_responsive_control('icon_size', [
            'label'      => 'Veľkosť ikonky',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 12, 'max' => 60 ] ],
            'selectors'  => [ '{{WRAPPER}} .faq__item summary::after' => 'font-size: {{SIZE}}{{UNIT}};' ],
        ]);
        $this->add_control('line_color', [
            'label'     => 'Farba čiar',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .faq__item' => 'border-color: {{VALUE}};' ],
        ]);
        $this->add_responsive_control('list_width', [
            'label'      => 'Max. šírka zoznamu',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px', '%' ],
            'range'      => [ 'px' => [ 'min' => 400, 'max' => 1240 ] ],
            'selectors'  => [ '{{WRAPPER}} .faq__list' => 'max-width: {{SIZE}}{{UNIT}};' ],
        ]);
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
