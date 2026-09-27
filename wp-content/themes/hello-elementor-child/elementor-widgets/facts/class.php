<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_Facts extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'facts'; }
    public function get_title()      { return 'CN · Čísla / fakty'; }
    public function get_icon()       { return 'eicon-counter'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'facts-script' ]; }
    public function get_style_depends()  { return [ 'facts-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Dlaždice',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);

        $repeater = new \Elementor\Repeater();
        $repeater->add_control('label', [
            'label'   => 'Popisok',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Ročník',
        ]);
        $repeater->add_control('value', [
            'label'   => 'Číslo / hodnota',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '27.',
        ]);
        $repeater->add_control('suffix', [
            'label'   => 'Doplnok k číslu (malý text)',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $repeater->add_control('text', [
            'label'   => 'Text',
            'type'    => \Elementor\Controls_Manager::WYSIWYG,
            'default' => '',
        ]);

        $this->add_control('items', [
            'label'       => 'Dlaždice',
            'type'        => \Elementor\Controls_Manager::REPEATER,
            'fields'      => $repeater->get_controls(),
            'title_field' => '{{{ label }}} — {{{ value }}}',
            'default'     => [
                [ 'label' => 'Ročník',       'value' => '27.', 'suffix' => '',            'text' => '<p>WG a 27. medzinárodná pozývacia súťaž — jedna z najdlhších tradícií modernej gymnastiky na Slovensku.</p>' ],
                [ 'label' => 'Súťažné dni',  'value' => '4',   'suffix' => '26. – 29. 11.', 'text' => '<p>Štvrtok až nedeľa. Kvalifikácie, finále aj majstrovstvá Slovenska v jednej hale.</p>' ],
                [ 'label' => 'Štartové pole', 'value' => 'INT', 'suffix' => '',           'text' => '<p>Medzinárodná pozývacia súťaž — kluby zo Slovenska a zahraničia, jednotlivkyne aj spoločné skladby.</p>' ],
                [ 'label' => 'Náčinie',      'value' => '5',   'suffix' => '',            'text' => '<p>Švihadlo, obruč, lopta, kužele a stuha — päť náčiní, ktoré rozhodujú o každom bode.</p>' ],
            ],
        ]);

        $this->end_controls_section();

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'label',  'Popisok',       '{{WRAPPER}} .facts__label' ],
            [ 'value',  'Číslo',         '{{WRAPPER}} .facts__value', 'gradient' ],
            [ 'suffix', 'Doplnok čísla', '{{WRAPPER}} .facts__value small' ],
            [ 'text',   'Text',          '{{WRAPPER}} .facts__text' ],
        ]);
        $this->start_controls_section('style_lines', [
            'label' => 'Deliace čiary',
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('line_color', [
            'label'     => 'Farba čiar',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .facts__grid, {{WRAPPER}} .facts__item' => 'border-color: {{VALUE}};' ],
        ]);
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
