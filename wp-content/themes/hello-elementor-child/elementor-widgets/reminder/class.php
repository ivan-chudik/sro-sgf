<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_Reminder extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'reminder'; }
    public function get_title()      { return 'CN · Pripomienka (formulár)'; }
    public function get_icon()       { return 'eicon-mail'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'reminder-script' ]; }
    public function get_style_depends()  { return [ 'reminder-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Obsah',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_intro_controls(
            'Ešte sa rozhoduješ?',
            'Pošleme ti pripomienku pred štartom predaja a súťaže',
            '<p>Žiadny spam — max. tri e-maily: štart predaja, program a deň pred súťažou.</p>'
        );
        $this->xn_show_control( 'ribbons_show', 'Zobraziť dekoratívnu stuhu' );
        $this->end_controls_section();

        $this->start_controls_section( 'section_form', [
            'label' => 'Formulár',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->add_control('form_template', [
            'label'       => 'Šablóna s Elementor Form',
            'type'        => \Elementor\Controls_Manager::SELECT,
            'options'     => xn_elementor_templates(),
            'default'     => '',
            'description' => 'Uložená šablóna (Container) s natívnym Form widgetom: 1 pole E-mail + tlačidlo. Samostatná pre SK a EN.',
        ]);
        $this->xn_show_control( 'note_show', 'Zobraziť text súhlasu' );
        $this->add_control('note', [
            'label'     => 'Text súhlasu (GDPR)',
            'type'      => \Elementor\Controls_Manager::WYSIWYG,
            'default'   => '<p>Odoslaním súhlasíš so spracovaním e-mailu na účel pripomienky. <a href="https://www.sgf.sk/sk/article/gdpr" target="_blank" rel="noopener">Ochrana osobných údajov</a></p>',
            'condition' => [ 'note_show' => 'yes' ],
        ]);
        $this->end_controls_section();

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'eyebrow', 'Eyebrow',       '{{WRAPPER}} .xn-eyebrow' ],
            [ 'title',   'Nadpis',        '{{WRAPPER}} .xn-h2' ],
            [ 'lead',    'Text',          '{{WRAPPER}} .xn-lead' ],
            [ 'note',    'Text súhlasu',  '{{WRAPPER}} .reminder__note' ],
        ]);
        $this->xn_style_cards([
            [ 'box', 'Biely box', '{{WRAPPER}} .reminder__box' ],
        ]);
        $this->xn_style_buttons( '{{WRAPPER}} .reminder__form .elementor-button', 'Tlačidlo formulára' );
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
