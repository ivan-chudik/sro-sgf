<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Elementor_Widget_Competition extends \Elementor\Widget_Base {

    use Xn_Widget_Controls;

    public function get_name()       { return 'competition'; }
    public function get_title()      { return 'CN · Kto súťaží'; }
    public function get_icon()       { return 'eicon-info-box'; }
    public function get_categories() { return [ 'custom-widgets' ]; }

    public function get_script_depends() { return [ 'competition-script' ]; }
    public function get_style_depends()  { return [ 'competition-style' ]; }

    protected function box_head_controls( $b, $k, $title, $text ) {
        $this->xn_show_control( $b . '_show', 'Zobraziť box' );
        $this->add_control( $b . '_k', [
            'label'   => 'Malý nadpis',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => $k,
        ]);
        $this->add_control( $b . '_title', [
            'label'   => 'Nadpis',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => $title,
        ]);
        $this->add_control( $b . '_text', [
            'label'   => 'Text',
            'type'    => \Elementor\Controls_Manager::WYSIWYG,
            'default' => $text,
        ]);
    }

    protected function register_controls() {

        $this->start_controls_section( 'section_content', [
            'label' => 'Hlavička sekcie',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->xn_anchor_control( 'sutaz' );
        $this->xn_intro_controls(
            'Pre kluby a pretekárky',
            'Kto súťaží a v čom',
            '<p>Vekové kategórie, súťažné programy a dokumenty podľa rozpisu súťaže. Časy a štartové listiny doplníme po uzávierke prihlášok.</p>'
        );
        $this->xn_show_control( 'ribbons_show', 'Zobraziť dekoratívnu stuhu' );
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
        $this->end_controls_section();

        $this->start_controls_section( 'section_box1', [
            'label' => 'Box 1 — kategórie',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->box_head_controls( 'box1', 'Vekové kategórie', 'Od najmladších po seniorky',
            '<p>Tri súťaže v jednej hale — súťaž World Gymnastics, medzinárodná pozývacia súťaž Open a majstrovstvá Slovenska spoločných skladieb.</p>' );
        $this->add_control('box1_list', [
            'label'       => 'Kategórie',
            'type'        => \Elementor\Controls_Manager::TEXTAREA,
            'rows'        => 6,
            'description' => '1 riadok = 1 kategória: "štítok | názov | doplnok"',
            'default'     => "WG | Seniorky a juniorky | jednotlivkyne s licenciou FIG · viacboj a finále na náčiní\n"
                           . "Open | Jednotlivkyne | ročníky 2019 až 2013 · juniorky 2011–2012 · seniorky 2010 a staršie\n"
                           . "Open | Spoločné skladby, dvojice a trojice | Babies 2018–2019 · Children 2017 a ml. · Hopes 2015 a ml. · PreJunior 2013 a ml. · Junior 2011–2012 · Senior 2010 a st.\n"
                           . "MSR | Spoločné skladby | majstrovstvá Slovenskej republiky",
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_box2', [
            'label' => 'Box 2 — programy',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->box_head_controls( 'box2', 'Súťažné programy', 'Jednotlivkyne a spoločné skladby',
            '<p>Moderná gymnastika s náčiním — švihadlo, obruč, lopta, kužele a stuha.</p>' );
        $this->add_control('box2_chips_grad', [
            'label'       => 'Štítky s gradientom (1 riadok = 1 štítok)',
            'type'        => \Elementor\Controls_Manager::TEXTAREA,
            'rows'        => 2,
            'default'     => "Jednotlivkyne\nSpoločné skladby",
        ]);
        $this->add_control('box2_chips', [
            'label'   => 'Štítky s obrysom (1 riadok = 1 štítok)',
            'type'    => \Elementor\Controls_Manager::TEXTAREA,
            'rows'    => 5,
            'default' => "Švihadlo\nObruč\nLopta\nKužele\nStuha",
        ]);
        $this->add_control('box2_note', [
            'label'   => 'Poznámka dole',
            'type'    => \Elementor\Controls_Manager::WYSIWYG,
            'default' => '<p><b>WG:</b> viacboj s obručou, loptou, kužeľmi a stuhou je zároveň kvalifikáciou, v sobotu nasledujú finále na náčiní pre 8 najlepších. <b>Open:</b> jednotlivkyne max. 2 zostavy podľa ročníka, spoločné skladby podľa kategórie.</p>',
        ]);
        $this->end_controls_section();

        $this->start_controls_section( 'section_box3', [
            'label' => 'Box 3 — dokumenty',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);
        $this->box_head_controls( 'box3', 'Dokumenty', 'Na stiahnutie',
            '<p>Rozpis, registrácia, harmonogram, štartové listiny a výsledky — všetko na jednom mieste.</p>' );

        $doc = new \Elementor\Repeater();
        $doc->add_control('title', [
            'label'   => 'Názov',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ]);
        $doc->add_control('url', [
            'label'       => 'Odkaz / PDF',
            'type'        => \Elementor\Controls_Manager::URL,
            'description' => 'Prázdne = dokument ešte nie je k dispozícii (zobrazí sa prerušovane).',
        ]);
        $doc->add_control('meta', [
            'label'   => 'Štítok vpravo',
            'type'    => \Elementor\Controls_Manager::TEXT,
            'default' => 'PDF',
        ]);
        $this->add_control('box3_docs', [
            'label'       => 'Dokumenty',
            'type'        => \Elementor\Controls_Manager::REPEATER,
            'fields'      => $doc->get_controls(),
            'title_field' => '{{{ title }}}',
            'default'     => [
                [ 'title' => 'Rozpis Open 2026 · slovenská verzia',  'url' => [ 'url' => xn_asset( 'docs/christmas-nitra-2026-rozpis-open-sk.pdf' ) ],   'meta' => 'PDF · SK' ],
                [ 'title' => 'Rozpis WG súťaže 2026 · Directives',   'url' => [ 'url' => xn_asset( 'docs/christmas-nitra-2026-directives-wg.pdf' ) ],    'meta' => 'PDF · EN' ],
                [ 'title' => 'Rozpis Open 2026 · Directives',        'url' => [ 'url' => xn_asset( 'docs/christmas-nitra-2026-directives-open.pdf' ) ],  'meta' => 'PDF · EN' ],
                [ 'title' => 'Registrácia · KSIS (rgform.eu)',       'url' => [ 'url' => 'https://www.rgform.eu/menu.php?akcia=NP&id_prop=4181' ],      'meta' => 'Online' ],
                [ 'title' => 'Harmonogram tréningov a súťaže',       'url' => [ 'url' => '' ], 'meta' => 'Po 18. 10.' ],
                [ 'title' => 'Štartové listiny',                     'url' => [ 'url' => '' ], 'meta' => 'Po 18. 10.' ],
                [ 'title' => 'Výsledky',                             'url' => [ 'url' => '' ], 'meta' => 'Po súťaži' ],
            ],
        ]);
        $this->add_control('box3_note', [
            'label'   => 'Poznámka dole',
            'type'    => \Elementor\Controls_Manager::WYSIWYG,
            'default' => '<p><b>Termíny pre kluby:</b> definitívne prihlášky 30. 9. 2026 · menovité prihlášky 18. 10. 2026 · hudba do KSIS 1. 11. 2026 · <a href="mailto:christmas.nitra@gmail.com">christmas.nitra@gmail.com</a></p>',
        ]);
        $this->end_controls_section();

        $this->xn_style_section();
        $this->xn_style_texts([
            [ 'eyebrow',   'Eyebrow',        '{{WRAPPER}} .xn-eyebrow' ],
            [ 'title',     'Nadpis sekcie',  '{{WRAPPER}} .xn-h2' ],
            [ 'lead',      'Úvodný text',    '{{WRAPPER}} .xn-lead' ],
            [ 'box_k',     'Box — malý nadpis', '{{WRAPPER}} .competition__k' ],
            [ 'box_title', 'Box — nadpis',   '{{WRAPPER}} .competition__title' ],
            [ 'box_text',  'Box — text',     '{{WRAPPER}} .competition__text' ],
            [ 'box_note',  'Box — poznámka', '{{WRAPPER}} .competition__note' ],
        ]);
        $this->xn_style_cards([
            [ 'box', 'Box', '{{WRAPPER}} .competition__box' ],
        ]);
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        include __DIR__ . '/template.php';
    }
}
