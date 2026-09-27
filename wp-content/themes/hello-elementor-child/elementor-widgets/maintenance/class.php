<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_Maintenance extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'maintenance'; }
    public function get_title()      { return 'CN · Maintenance / Coming soon'; }
    public function get_icon()       { return 'eicon-lock'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'maintenance-script' ]; }
    public function get_style_depends()  { return [ 'maintenance-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Obsah',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'logo_show', 'Zobraziť logo' );
        $this->xn_media_controls( 'logo', 'Logo', xn_asset( 'logos/sgf-white.png' ), '{{WRAPPER}} .maintenance__logo', 'Slovenská gymnastická federácia' );
        $this->xn_show_control( 'date_show', 'Zobraziť dátum' );
        $this->add_control('date_text', [
            'label'     => 'Dátum',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => '26. – 29. novembra 2026 · Nitra',
            'condition' => [ 'date_show' => 'yes' ],
        ]);
        $this->add_control('title', [
            'label'   => 'Nadpis (H1)',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Christmas Nitra',
        ]);
        $this->xn_show_control( 'subtitle_show', 'Zobraziť podnadpis' );
        $this->add_control('subtitle', [
            'label'     => 'Podnadpis',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Slovak Rhythmic Gymnastics Open',
            'condition' => [ 'subtitle_show' => 'yes' ],
        ]);
        $this->xn_show_control( 'text_show', 'Zobraziť text' );
        $this->add_control('text', [
            'label'     => 'Text',
            'type'      => \Elementor\Controls_Manager::WYSIWYG,
            'default'   => '<p>Stránku práve pripravujeme. Čoskoro tu nájdeš vstupenky, livestream, program a všetky informácie o súťaži.</p><p lang="en">We are preparing the website. Tickets, livestream and schedule coming soon.</p>',
            'condition' => [ 'text_show' => 'yes' ],
        ]);
        $this->xn_show_control( 'contact_show', 'Zobraziť kontakt' );
        $this->add_control('contact', [
            'label'     => 'Kontakt',
            'type'      => \Elementor\Controls_Manager::WYSIWYG,
            'default'   => '<p><a href="mailto:christmas.nitra@gmail.com">christmas.nitra@gmail.com</a> · <a href="https://sgf.sk" target="_blank" rel="noopener">www.sgf.sk</a></p>',
            'condition' => [ 'contact_show' => 'yes' ],
        ]);
        $this->xn_show_control( 'ribbons_show', 'Zobraziť dekoratívne stuhy' );
        $this->end_controls_section();

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'date',     'Dátum',      '{{WRAPPER}} .maintenance__date' ],
            [ 'title',    'Nadpis',     '{{WRAPPER}} .maintenance__title', 'gradient' ],
            [ 'subtitle', 'Podnadpis',  '{{WRAPPER}} .maintenance__sub', 'gradient' ],
            [ 'text',     'Text',       '{{WRAPPER}} .maintenance__text' ],
            [ 'contact',  'Kontakt',    '{{WRAPPER}} .maintenance__contact' ],
        ]);
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
