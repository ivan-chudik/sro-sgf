<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_Pricing extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'pricing'; }
    public function get_title()      { return 'CN · Vstupenky a ceny'; }
    public function get_icon()       { return 'eicon-price-table'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'pricing-script' ]; }
    public function get_style_depends()  { return [ 'pricing-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Hlavička sekcie',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_anchor_control( 'vstupenky' );
        $this->xn_show_control( 'line_show', 'Zobraziť gradientovú linku nad sekciou' );
        $this->xn_show_control( 'parallax_show', 'Zobraziť parallax stuhy' );
        $this->xn_intro_controls(
            'Vstupenky a ceny',
            'Jeden deň, dva dni alebo celá súťaž',
            '<p>Rovnaké ceny pre osobnú účasť aj livestream. Do 31. 10. 2026 platí Early Bird, potom plná cena. Ceny sú konečné, nič sa nepripočítava.</p>'
        );
        $this->xn_heading_control( 'badge_heading', 'Early Bird odznak' );
        $this->xn_show_control( 'badge_show', 'Zobraziť odznak' );
        $this->add_control('badge_label', [
            'label'     => 'Text',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Early Bird',
            'condition' => [ 'badge_show' => 'yes' ],
        ]);
        $this->add_control('badge_prefix', [
            'label'     => 'Pred dátumom',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'do',
            'condition' => [ 'badge_show' => 'yes' ],
        ]);
        $this->add_control('badge_date', [
            'label'     => 'Dátum',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => '31. 10. 2026',
            'condition' => [ 'badge_show' => 'yes' ],
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_tabs', [
            'label' => 'Taby Naživo / Livestream',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->add_control('default_mode', [
            'label'   => 'Predvolený tab',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'live',
            'options' => [ 'live' => 'Naživo', 'stream' => 'Livestream' ],
        ]);
        $tabs = [
            'live'   => [ 'Tab Naživo',     'Naživo v Nitre', 'Vstupenka do haly · e-ticket s QR kódom', 'Osobná účasť',  'Naživo v hale' ],
            'stream' => [ 'Tab Livestream', 'Livestream',     'Online prenos · mobil, notebook, TV',     'stream.sgf.sk', 'Livestream · online' ],
        ];
        foreach ( $tabs as $m => $t ) {
            $this->xn_heading_control( 'tab_' . $m . '_heading', $t[0] );
            $this->add_control( 'tab_' . $m . '_title', [
                'label'   => 'Názov',
                'type'    => \Elementor\Controls_Manager::TEXT,
                'default' => $t[1],
            ]);
            $this->add_control( 'tab_' . $m . '_sub', [
                'label'   => 'Popis',
                'type'    => \Elementor\Controls_Manager::TEXT,
                'default' => $t[2],
            ]);
            $this->add_control( 'tab_' . $m . '_meta', [
                'label'   => 'Štítok vpravo',
                'type'    => \Elementor\Controls_Manager::TEXT,
                'default' => $t[3],
            ]);
            $this->add_control( 'mode_' . $m . '_label', [
                'label'   => 'Štítok na kartách',
                'type'    => \Elementor\Controls_Manager::TEXT,
                'default' => $t[4],
            ]);
        }
        $this->end_controls_section();

        $this->start_controls_section( 'section_plans', [
            'label' => 'Cenové karty',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->add_control('tickets_url', [
            'label'       => 'Odkaz tlačidiel',
            'type'        => \Elementor\Controls_Manager::URL,
            'placeholder' => 'prázdne = predaj vstupeniek (SK/EN)',
            'description' => $this->xn_tickets_desc(),
        ]);

        $plan = new \Elementor\Repeater();
        $plan->add_control('best', [
            'label'        => 'Zvýraznená karta',
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'return_value' => 'yes',
        ]);
        $plan->add_control('badge', [
            'label'   => 'Odznak nad kartou',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $plan->add_control('k', [
            'label'   => 'Počet dní (malý nadpis)',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '1 deň',
        ]);
        $plan->add_control('title_live', [
            'label'   => 'Nadpis — naživo',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $plan->add_control('title_stream', [
            'label'   => 'Nadpis — livestream',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $plan->add_control('sub_live', [
            'label'   => 'Podnadpis — naživo',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $plan->add_control('sub_stream', [
            'label'   => 'Podnadpis — livestream',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $plan->add_control('eb_label', [
            'label'   => 'Early Bird text nad cenou',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Early Bird do 31. 10.',
        ]);
        $plan->add_control('price', [
            'label'   => 'Cena',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $plan->add_control('was_label', [
            'label'   => 'Plná cena — text',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Od 1. 11.',
        ]);
        $plan->add_control('was_price', [
            'label'   => 'Plná cena',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $plan->add_control('per', [
            'label'       => 'Cena za deň',
            'type'        => \Elementor\Controls_Manager::TEXTAREA,
            'rows'        => 2,
            'default'     => '',
            'description' => '&lt;b&gt;…&lt;/b&gt; = zvýraznenie',
        ]);
        $plan->add_control('features', [
            'label'       => 'Body zoznamu',
            'type'        => \Elementor\Controls_Manager::TEXTAREA,
            'rows'        => 4,
            'default'     => '',
            'description' => '1 riadok = 1 bod. Rozdielny text pre tab: "naživo | livestream". Bez | platí pre oba taby.',
        ]);
        $plan->add_control('btn_live', [
            'label'   => 'Tlačidlo — naživo',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $plan->add_control('btn_stream', [
            'label'   => 'Tlačidlo — livestream',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $plan->add_control('btn_style', [
            'label'   => 'Štýl tlačidla',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'grad',
            'options' => [ 'primary' => 'Plné (biele)', 'grad' => 'Gradientový obrys' ],
        ]);
        $plan->add_control('btn_url', [
            'label'       => 'Vlastný odkaz tlačidla',
            'type'        => \Elementor\Controls_Manager::URL,
            'placeholder' => 'prázdne = odkaz tlačidiel vyššie',
        ]);

        $this->add_control('plans', [
            'label'       => 'Karty',
            'type'        => \Elementor\Controls_Manager::REPEATER,
            'fields'      => $plan->get_controls(),
            'title_field' => '{{{ k }}} — {{{ price }}}',
            'default'     => [
                [
                    'k' => '1 deň', 'title_live' => 'Jeden deň', 'title_stream' => 'Livestream — 1 deň',
                    'sub_live' => 'Vstup na ľubovoľný súťažný deň', 'sub_stream' => 'Prenos z ľubovoľného súťažného dňa',
                    'eb_label' => 'Early Bird do 31. 10.', 'price' => '6,80 €', 'was_label' => 'Od 1. 11.', 'was_price' => '8,00 €',
                    'per' => 'Cena za deň <b>6,80 €</b>',
                    'features' => "Všetky súťažné bloky daného dňa | Celý súťažný deň naživo\nIdeálne na sobotné finále\nE-vstupenka s QR kódom | Prístup ihneď po zaplatení",
                    'btn_live' => 'Kúpiť vstupenku · 1 deň', 'btn_stream' => 'Kúpiť livestream · 1 deň', 'btn_style' => 'grad',
                ],
                [
                    'k' => '2 dni', 'title_live' => 'Dva dni', 'title_stream' => 'Livestream — 2 dni',
                    'sub_live' => 'Dva ľubovoľné súťažné dni', 'sub_stream' => 'Prenos z dvoch súťažných dní',
                    'eb_label' => 'Early Bird do 31. 10.', 'price' => '12,75 €', 'was_label' => 'Od 1. 11.', 'was_price' => '15,00 €',
                    'per' => 'Cena za deň <b>6,38 €</b>',
                    'features' => "Napr. sobotné finále + nedeľné MSR\nVšetky bloky oboch dní | Oba dni naživo na všetkých zariadeniach\nE-vstupenka s QR kódom | Prístup ihneď po zaplatení",
                    'btn_live' => 'Kúpiť vstupenku · 2 dni', 'btn_stream' => 'Kúpiť livestream · 2 dni', 'btn_style' => 'grad',
                ],
                [
                    'best' => 'yes', 'badge' => 'Najlepšia hodnota',
                    'k' => '4 dni', 'title_live' => '4-dňový pass', 'title_stream' => 'Livestream — 4-dňový pass',
                    'sub_live' => 'Celá súťaž — štvrtok až nedeľa', 'sub_stream' => 'Celá súťaž naživo — štvrtok až nedeľa',
                    'eb_label' => 'Early Bird do 31. 10.', 'price' => '17,00 €', 'was_label' => 'Od 1. 11.', 'was_price' => '20,00 €',
                    'per' => 'Cena za deň <b>4,25 €</b> — dva dni v cene navyše',
                    'features' => "Všetky štyri dni vrátane finále\nJedna vstupenka, žiadne prekupovanie | Jeden prístup, všetky zariadenia\nSobotné finále a víkendové MSR v cene",
                    'btn_live' => 'Kúpiť 4-dňový pass', 'btn_stream' => 'Kúpiť livestream · 4 dni', 'btn_style' => 'primary',
                ],
            ],
        ]);

        $this->xn_show_control( 'fine_show', 'Zobraziť poznámku pod kartami' );
        $this->add_control('fine', [
            'label'     => 'Poznámka pod kartami',
            'type'      => \Elementor\Controls_Manager::WYSIWYG,
            'default'   => '<p><b>Early Bird</b> platí do 31. 10. 2026, od 1. 11. platí plná cena. Ceny sú konečné pre kupujúceho — bez ďalších poplatkov. Predaj a platba cez <a href="https://tickets.sgf.sk/sk/?add-to-cart=5964" target="_blank" rel="noopener">tickets.sgf.sk</a>; livestream sleduješ na <a href="https://stream.sgf.sk/" target="_blank" rel="noopener">stream.sgf.sk</a>. Podrobný harmonogram s časmi zverejníme po uzávierke prihlášok 18. 10. 2026.</p>',
            'condition' => [ 'fine_show' => 'yes' ],
        ]);
        $this->end_controls_section();

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'eyebrow',    'Eyebrow',             '{{WRAPPER}} .xn-eyebrow' ],
            [ 'title',      'Nadpis sekcie',       '{{WRAPPER}} .xn-h2' ],
            [ 'lead',       'Úvodný text',         '{{WRAPPER}} .xn-lead' ],
            [ 'tab',        'Tab — názov',         '{{WRAPPER}} .pricing__tab b' ],
            [ 'plan_k',     'Karta — počet dní',   '{{WRAPPER}} .pricing__k' ],
            [ 'plan_title', 'Karta — nadpis',      '{{WRAPPER}} .pricing__title' ],
            [ 'plan_sub',   'Karta — podnadpis',   '{{WRAPPER}} .pricing__sub' ],
            [ 'plan_price', 'Karta — cena',        '{{WRAPPER}} .pricing__price b', 'gradient' ],
            [ 'plan_list',  'Karta — zoznam',      '{{WRAPPER}} .pricing__list' ],
            [ 'fine',       'Poznámka',            '{{WRAPPER}} .pricing__fine' ],
        ]);
        $this->xn_style_cards([
            [ 'plan',      'Karta',              '{{WRAPPER}} .pricing__plan:not(.is-best)' ],
            [ 'plan_best', 'Zvýraznená karta',   '{{WRAPPER}} .pricing__plan.is-best' ],
            [ 'tabs',      'Prepínač tabov',     '{{WRAPPER}} .pricing__tabs' ],
        ]);
        $this->xn_style_buttons( '{{WRAPPER}} .pricing__plan .xn-btn' );
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
