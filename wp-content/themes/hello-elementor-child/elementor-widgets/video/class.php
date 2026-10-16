<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_Video extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'video'; }
    public function get_title()      { return 'CN · Video spot'; }
    public function get_icon()       { return 'eicon-youtube'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'video-script' ]; }
    public function get_style_depends()  { return [ 'video-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Hlavička sekcie',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_anchor_control( 'spot' );
        $this->xn_intro_controls( 'Spot · Christmas Nitra 2026', 'Toto je Christmas Nitra', '' );
        $this->end_controls_section();

        $this->start_controls_section( 'section_video', [
            'label' => 'Video',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->add_control('video_url', [
            'label'       => 'YouTube odkaz',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'label_block' => true,
            'default'     => 'https://www.youtube.com/watch?v=qLdxcPkqqYQ',
            'description' => 'Odkaz na video (youtube.com/watch?v=…, youtu.be/…) alebo len ID videa.',
        ]);
        $this->add_control('video_title', [
            'label'   => 'Názov videa (pre čítačky)',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Christmas Nitra 2026 — spot',
        ]);
        $this->add_control('poster', [
            'label'       => 'Náhľadový obrázok',
            'type'        => \Elementor\Controls_Manager::MEDIA,
            'description' => 'Prázdne = náhľad z YouTube.',
        ]);
        $this->add_responsive_control('frame_width', [
            'label'      => 'Šírka videa',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px', '%' ],
            'range'      => [ 'px' => [ 'min' => 280, 'max' => 1240 ], '%' => [ 'min' => 30, 'max' => 100 ] ],
            'selectors'  => [ '{{WRAPPER}} .video__frame' => 'max-width: {{SIZE}}{{UNIT}};' ],
        ]);
        $this->add_control('play_label', [
            'label'   => 'Text tlačidla prehrať (pre čítačky)',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'Prehrať video',
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_cta', [
            'label' => 'Tlačidlo',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_button_controls( 'btn1', 'Tlačidlo pod videom', 'Kúpiť vstupenku', 'primary', '', $this->xn_tickets_desc() );
        $this->end_controls_section();

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'eyebrow', 'Eyebrow',       '{{WRAPPER}} .xn-eyebrow' ],
            [ 'title',   'Nadpis sekcie', '{{WRAPPER}} .xn-h2' ],
            [ 'lead',    'Úvodný text',   '{{WRAPPER}} .xn-lead' ],
        ]);
        $this->xn_style_cards([
            [ 'frame', 'Rám videa', '{{WRAPPER}} .video__frame' ],
        ]);

        $this->start_controls_section( 'style_play', [
            'label' => 'Ikona prehrať',
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ]);
        $this->add_responsive_control('play_size', [
            'label'      => 'Veľkosť',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 40, 'max' => 160 ] ],
            'selectors'  => [ '{{WRAPPER}} .video__play' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ],
        ]);
        $this->add_control('play_bg', [
            'label'     => 'Pozadie',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .video__play' => 'background: {{VALUE}};' ],
        ]);
        $this->add_control('play_color', [
            'label'     => 'Farba ikony',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .video__play svg' => 'fill: {{VALUE}};' ],
        ]);
        $this->end_controls_section();

        $this->xn_style_buttons();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
