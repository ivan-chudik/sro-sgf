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
        $this->xn_anchor_control( 'program' );
        $this->xn_intro_controls(
            'Program súťaže',
            'Štyri dni, štyri rôzne zážitky',
            '<p>Tri súťaže v jednej hale: <b>WG</b> súťaž World Gymnastics, <b>Open</b> medzinárodná pozývacia súťaž a <b>MSR</b> spoločných skladieb. Nižšie sú hlavné body programu, podrobný harmonogram so všetkými kategóriami nájdeš v PDF.</p>'
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
            'description' => '1 riadok = 1 blok: "čas | štítok | text | doplnok" (čas je nepovinný). Napr. 16:30 - 20:30 | WG | Finále · juniorky a seniorky | slávnostné otvorenie 16:00',
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
                  'items' => "08:00 - 12:25 | Open | Jednotlivkyne · ročníky 2019 - 2016 | 12:25 vyhlásenie výsledkov\n13:00 - 16:45 | Open | Jednotlivkyne · ročníky 2015 - 2014 | 17:00 vyhlásenie výsledkov\n18:00 - 22:00 | WG | Pódiový tréning" ],
                [ 'label' => 'Deň 2', 'title' => 'Piatok', 'date' => '27. novembra 2026', 'btn_text' => 'Vstupenka · deň 2', 'btn_style' => 'grad',
                  'items' => "08:00 - 14:30 | WG | Kvalifikácia · juniorky\n14:40 - 16:20 | Open | Jednotlivkyne · juniorky 2011 - 2012 | vyhlásenie výsledkov junioriek WG a Open · slávnostné otvorenie súťaže\n17:00 - 20:30 | WG | Kvalifikácia · seniorky | 20:30 vyhlásenie výsledkov" ],
                [ 'label' => 'Deň 3', 'title' => 'Sobota', 'date' => '28. novembra 2026', 'btn_text' => 'Vstupenka · finále', 'btn_style' => 'primary',
                  'items' => "08:00 - 11:05 | Open | Jednotlivkyne · ročník 2013 a seniorky | 11:10 vyhlásenie výsledkov\n11:30 - 14:30 | MSR | Spoločné skladby bez náčinia · 2020 - 2017 a mladšie | 11:30 slávnostné otvorenie MSR · 14:35 vyhlásenie výsledkov\n16:00 - 20:30 | WG | Finále · juniorky a seniorky | 16:00 slávnostné otvorenie finále" ],
                [ 'label' => 'Deň 4', 'title' => 'Nedeľa', 'date' => '29. novembra 2026', 'btn_text' => 'Vstupenka · deň 4', 'btn_style' => 'grad',
                  'items' => "09:00 - 11:20 | MSR | Dvojice a trojice bez náčinia | 08:40 slávnostné otvorenie MSR\n11:30 - 13:10 | MSR | Dvojice a trojice s náčiním | 13:15 vyhlásenie výsledkov\n14:10 - 15:20 | MSR | Spoločné skladby · nádeje a juniorky\n15:30 - 16:25 | MSR | Spoločné skladby · pre-juniorky a seniorky | 16:30 vyhlásenie výsledkov Vianočnej Nitry a MSR" ],
            ],
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_pdf', [
            'label' => 'Tlačidlo — celý program',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_button_controls( 'pdf', 'Tlačidlo pod programom', 'Celý program (PDF)', 'primary', 'https://sro.sgf.sk/wp-content/uploads/2026/11/CHN_Timetable.pdf' );
        $this->end_controls_section();

        $this->start_controls_section( 'section_note', [
            'label' => 'Poznámka',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'note_show', 'Zobraziť poznámku' );
        $this->add_control('note_label', [
            'label'     => 'Štítok',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Aktualizované 13. 11. 2026',
            'condition' => [ 'note_show' => 'yes' ],
        ]);
        $this->add_control('note_text', [
            'label'     => 'Text',
            'type'      => \Elementor\Controls_Manager::WYSIWYG,
            'default'   => '<p>Časy sú orientačné. Organizátor si vyhradzuje právo na zmenu programu.</p>',
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
            [ 'day_time',  'Deň — čas bloku', '{{WRAPPER}} .schedule__time' ],
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
