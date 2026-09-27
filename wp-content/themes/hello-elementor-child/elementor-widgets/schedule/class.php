<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_Schedule extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'schedule'; }
    public function get_title()      { return 'CN · Program súťaže'; }
    public function get_icon()       { return 'eicon-calendar'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'schedule-script' ]; }
    public function get_style_depends()  { return [ 'schedule-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Hlavička sekcie',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_anchor_control( 'harmonogram' );
        $this->xn_intro_controls(
            'Program súťaže',
            'Štyri dni, štyri rôzne zážitky',
            '<p>Predbežný program podľa rozpisu súťaže — tri súťaže v jednej hale: <b>WG</b> súťaž World Gymnastics, <b>Open</b> medzinárodná pozývacia súťaž a <b>MSR</b> spoločných skladieb. Presné časy zverejníme po uzávierke prihlášok 18. 10. 2026.</p>'
        );
        $this->end_controls_section();

        $this->start_controls_section( 'section_days', [
            'label' => 'Dni',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->add_control('tags_grad', [
            'label'       => 'Štítky s gradientom',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => 'WG',
            'description' => 'Oddelené čiarkou. Ostatné štítky sú čierne.',
        ]);
        $this->add_control('tags_orchid', [
            'label'   => 'Štítky fialové',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'MSR, SVK',
        ]);
        $this->add_control('tickets_url', [
            'label'       => 'Odkaz tlačidiel',
            'type'        => \Elementor\Controls_Manager::URL,
            'placeholder' => 'prázdne = predaj vstupeniek (SK/EN)',
            'description' => $this->xn_tickets_desc(),
        ]);

        $day = new \Elementor\Repeater();
        $day->add_control('label', [
            'label'   => 'Deň',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Deň 1',
        ]);
        $day->add_control('title', [
            'label'   => 'Nadpis',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $day->add_control('date', [
            'label'   => 'Dátum',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $day->add_control('items', [
            'label'       => 'Bloky programu',
            'type'        => \Elementor\Controls_Manager::TEXTAREA,
            'rows'        => 4,
            'default'     => '',
            'description' => '1 riadok = 1 blok: "štítok | text | doplnok". Napr. WG | Finále na náčiní | vyhlásenie výsledkov',
        ]);
        $day->add_control('btn_text', [
            'label'   => 'Text tlačidla',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $day->add_control('btn_style', [
            'label'   => 'Štýl tlačidla',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'default' => 'grad',
            'options' => [ 'primary' => 'Plné (čierne)', 'grad' => 'Gradientový obrys' ],
        ]);
        $day->add_control('btn_url', [
            'label'       => 'Vlastný odkaz tlačidla',
            'type'        => \Elementor\Controls_Manager::URL,
            'placeholder' => 'prázdne = odkaz tlačidiel vyššie',
        ]);

        $this->add_control('days', [
            'label'       => 'Dni',
            'type'        => \Elementor\Controls_Manager::REPEATER,
            'fields'      => $day->get_controls(),
            'title_field' => '{{{ label }}} — {{{ title }}}',
            'default'     => [
                [ 'label' => 'Deň 1', 'title' => 'Štvrtok', 'date' => '26. novembra 2026', 'btn_text' => 'Vstupenka · deň 1', 'btn_style' => 'grad',
                  'items' => "WG | Tréningy a pódiové tréningy | seniorky a juniorky\nOpen | Jednotlivkyne · 1. deň | medzinárodná pozývacia súťaž" ],
                [ 'label' => 'Deň 2', 'title' => 'Piatok', 'date' => '27. novembra 2026', 'btn_text' => 'Vstupenka · deň 2', 'btn_style' => 'grad',
                  'items' => "WG | Kvalifikácia · viacboj | seniorky a juniorky · obruč, lopta, kužele, stuha\nOpen | Jednotlivkyne · 2. deň | medzinárodná pozývacia súťaž" ],
                [ 'label' => 'Deň 3', 'title' => 'Sobota', 'date' => '28. novembra 2026', 'btn_text' => 'Vstupenka · finále', 'btn_style' => 'primary',
                  'items' => "WG | Finále na náčiní | obruč, lopta, kužele, stuha · vyhlásenie výsledkov\nOpen | Jednotlivkyne a spoločné skladby\nMSR | Majstrovstvá SR spoločných skladieb" ],
                [ 'label' => 'Deň 4', 'title' => 'Nedeľa', 'date' => '29. novembra 2026', 'btn_text' => 'Vstupenka · deň 4', 'btn_style' => 'grad',
                  'items' => "Open | Spoločné skladby | dvojice a trojice\nMSR | Majstrovstvá SR spoločných skladieb | vyhlásenie výsledkov" ],
            ],
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_note', [
            'label' => 'Poznámka',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'note_show', 'Zobraziť poznámku' );
        $this->add_control('note_label', [
            'label'     => 'Štítok',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Predbežný program',
            'condition' => [ 'note_show' => 'yes' ],
        ]);
        $this->add_control('note_text', [
            'label'     => 'Text',
            'type'      => \Elementor\Controls_Manager::WYSIWYG,
            'default'   => '<p>Časy blokov a štartové listiny doplníme po 18. 10. 2026. Organizátor si vyhradzuje právo upraviť program podľa počtu prihlásených.</p>',
            'condition' => [ 'note_show' => 'yes' ],
        ]);
        $this->end_controls_section();

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'eyebrow',   'Eyebrow',        '{{WRAPPER}} .xn-eyebrow' ],
            [ 'title',     'Nadpis sekcie',  '{{WRAPPER}} .xn-h2' ],
            [ 'lead',      'Úvodný text',    '{{WRAPPER}} .xn-lead' ],
            [ 'day_label', 'Deň — štítok',   '{{WRAPPER}} .schedule__label' ],
            [ 'day_title', 'Deň — nadpis',   '{{WRAPPER}} .schedule__title' ],
            [ 'day_date',  'Deň — dátum',    '{{WRAPPER}} .schedule__date' ],
            [ 'day_item',  'Deň — bloky',    '{{WRAPPER}} .schedule__list li' ],
            [ 'note',      'Poznámka',       '{{WRAPPER}} .schedule__note' ],
        ]);
        $this->xn_style_cards([
            [ 'day', 'Karta dňa', '{{WRAPPER}} .schedule__day' ],
        ]);
        $this->xn_style_buttons();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
