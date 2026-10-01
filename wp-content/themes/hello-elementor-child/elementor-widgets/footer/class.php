<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_Footer extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'footer'; }
    public function get_title()      { return 'CN · Footer'; }
    public function get_icon()       { return 'eicon-footer'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'footer-script' ]; }
    public function get_style_depends()  { return [ 'footer-style' ]; }

    protected function register_controls() {

        $this->start_controls_section( 'section_columns', [
            'label' => 'Stĺpce',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $col = new \Elementor\Repeater();
        $col->add_control('title', [
            'label'   => 'Nadpis',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $col->add_control('content', [
            'label'   => 'Obsah',
            'type'    => \Elementor\Controls_Manager::WYSIWYG,
            'default' => '',
        ]);
        $col->add_control('social', [
            'label'        => 'Pod obsahom zobraziť ikonky sociálnych sietí',
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'return_value' => 'yes',
        ]);
        $this->add_control('columns', [
            'label'       => 'Stĺpce',
            'type'        => \Elementor\Controls_Manager::REPEATER,
            'fields'      => $col->get_controls(),
            'title_field' => '{{{ title }}}',
            'default'     => [
                [ 'title' => 'Organizátor', 'content' => '<p>ŠK ŠOG Nitra – moderná gymnastika | MODERGYM<br />Slančíkovej 2, 950 50 Nitra<br />Zuzana Vilčeková · <a href="tel:+421911430001">+421 911 430 001</a><br /><a href="mailto:christmas.nitra@gmail.com">christmas.nitra@gmail.com</a> · <a href="http://www.gymnastikanitra.sk/" target="_blank" rel="noopener">gymnastikanitra.sk</a></p>' ],
                [ 'title' => 'Slovenská gymnastická federácia', 'social' => 'yes', 'content' => '<p>Olympijské námestie 1, 832 80 Bratislava<br /><a href="mailto:office@sgf.sk">office@sgf.sk</a> · <a href="https://sgf.sk" target="_blank" rel="noopener">www.sgf.sk</a><br />v spolupráci so Strednou športovou školou Nitra</p>' ],
                [ 'title' => 'Vstupenky a livestream', 'content' => '<p>Podpora: <a href="mailto:roman@alttag.media">roman@alttag.media</a><br /><a href="https://tickets.sgf.sk/sk/?add-to-cart=5964" target="_blank" rel="noopener">tickets.sgf.sk</a> · <a href="https://stream.sgf.sk/" target="_blank" rel="noopener">stream.sgf.sk</a></p>' ],
                [ 'title' => 'Sleduj nás', 'content' => '<p><a href="https://www.facebook.com/gymnastikanitra/" target="_blank" rel="noopener">Facebook · ŠK ŠOG Nitra</a><br /><a href="https://www.instagram.com/christmasnitra2026/" target="_blank" rel="noopener">Instagram · @christmasnitra2026</a><br /><a href="https://www.instagram.com/sksognitra_rhythmic_gymnastics/" target="_blank" rel="noopener">Instagram · @sksognitra_rhythmic_gymnastics</a></p>' ],
            ],
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_social', [
            'label' => 'Sociálne siete SGF',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->add_control('social_label', [
            'label'   => 'Popis pre čítačky (aria-label)',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'SGF na sociálnych sieťach',
        ]);
        $networks = [
            'fb' => [ 'Facebook',  'https://www.facebook.com/slovenskagymnastickafederacia/', 'SGF na Facebooku' ],
            'ig' => [ 'Instagram', 'https://www.instagram.com/slovenskagymnastickafederacia/', 'SGF na Instagrame' ],
            'yt' => [ 'YouTube',   'https://www.youtube.com/channel/UCdN1r_NUaD12itn7xFBv_ag', 'SGF na YouTube' ],
        ];
        foreach ( $networks as $n => $d ) {
            $this->add_control( $n . '_url', [
                'label'       => $d[0] . ' — odkaz',
                'type'        => \Elementor\Controls_Manager::URL,
                'default'     => [ 'url' => $d[1], 'is_external' => true ],
                'description' => 'Prázdne = ikonka sa nezobrazí.',
            ]);
            $this->add_control( $n . '_label', [
                'label'   => $d[0] . ' — popis (aria-label)',
                'type'    => \Elementor\Controls_Manager::TEXT,
                'default' => $d[2],
            ]);
        }
        $this->end_controls_section();

        $this->start_controls_section( 'section_bottom', [
            'label' => 'Spodný riadok',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_show_control( 'bottom_show', 'Zobraziť spodný riadok' );
        $this->add_control('bottom_left', [
            'label'     => 'Vľavo',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => '© 2026 Slovenská gymnastická federácia · ŠK ŠOG Nitra',
            'condition' => [ 'bottom_show' => 'yes' ],
        ]);
        $this->add_control('bottom_right', [
            'label'     => 'Vpravo',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Christmas Nitra — Slovak Rhythmic Gymnastics Open · WG a 27. medzinárodná pozývacia súťaž',
            'condition' => [ 'bottom_show' => 'yes' ],
        ]);
        $this->end_controls_section();

        $this->xn_style_section( '{{WRAPPER}} .custom-footer' );
        $this->xn_style_texts([
            [ 'col_title', 'Nadpis stĺpca',   '{{WRAPPER}} .footer__col h4' ],
            [ 'col_text',  'Text stĺpca',     '{{WRAPPER}} .footer__text' ],
            [ 'col_link',  'Odkazy',          '{{WRAPPER}} .footer__text a' ],
            [ 'bottom',    'Spodný riadok',   '{{WRAPPER}} .footer__bottom' ],
        ]);
        $this->start_controls_section('style_social', [
            'label' => 'Ikonky sociálnych sietí',
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('social_color', [
            'label'     => 'Farba ikonky',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .footer__soc a' => 'color: {{VALUE}};' ],
        ]);
        $this->add_control('social_border', [
            'label'     => 'Farba obrysu',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .footer__soc a' => 'border-color: {{VALUE}};' ],
        ]);
        $this->add_responsive_control('social_size', [
            'label'      => 'Veľkosť ikonky',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 10, 'max' => 40 ] ],
            'selectors'  => [ '{{WRAPPER}} .footer__soc svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};' ],
        ]);
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
