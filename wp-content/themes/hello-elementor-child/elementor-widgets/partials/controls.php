<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;

/**
 * Zdieľané stavebné bloky controls pre Christmas Nitra widgety.
 * Style controls nemajú defaulty — bez zásahu platí dizajn zo style.css (vrátane breakpointov 1100/820 px).
 */
trait Xn_Widget_Controls {

    // ── CONTENT ──────────────────────────────────────────────────────

    protected function xn_anchor_control( $default ) {
        $this->add_control('anchor_id', [
            'label'   => 'Anchor ID (bez #) — používa menu / scroll',
            'type'    => Controls_Manager::TEXT,
            'default' => $default,
        ]);
    }

    protected function xn_show_control( $id, $label, $default = 'yes' ) {
        $this->add_control($id, [
            'label'        => $label,
            'type'         => Controls_Manager::SWITCHER,
            'label_on'     => 'Áno',
            'label_off'    => 'Nie',
            'return_value' => 'yes',
            'default'      => $default,
        ]);
    }

    protected function xn_heading_control( $id, $label ) {
        $this->add_control($id, [
            'label'     => $label,
            'type'      => Controls_Manager::HEADING,
            'separator' => 'before',
        ]);
    }

    /** Hlavička sekcie: eyebrow + nadpis H2 + úvodný text (každé so samostatným show/hide). */
    protected function xn_intro_controls( $eyebrow, $title, $lead = null ) {
        $this->xn_show_control('eyebrow_show', 'Zobraziť eyebrow');
        $this->add_control('eyebrow', [
            'label'     => 'Eyebrow',
            'type'      => Controls_Manager::TEXT,
            'default'   => $eyebrow,
            'condition' => [ 'eyebrow_show' => 'yes' ],
        ]);
        $this->xn_show_control('title_show', 'Zobraziť nadpis');
        $this->add_control('title', [
            'label'       => 'Nadpis',
            'type'        => Controls_Manager::TEXTAREA,
            'rows'        => 2,
            'default'     => $title,
            'condition'   => [ 'title_show' => 'yes' ],
        ]);
        if ( $lead !== null ) {
            $this->xn_show_control('lead_show', 'Zobraziť úvodný text');
            $this->add_control('lead', [
                'label'     => 'Úvodný text',
                'type'      => Controls_Manager::WYSIWYG,
                'default'   => $lead,
                'condition' => [ 'lead_show' => 'yes' ],
            ]);
        }
    }

    protected function xn_render_intro( $s, $title_tag = 'h2' ) {
        if ( xn_on( $s, 'eyebrow_show' ) && xn_filled( $s, 'eyebrow' ) ) {
            echo '<div class="xn-eyebrow">' . esc_html( $s['eyebrow'] ) . '</div>';
        }
        if ( xn_on( $s, 'title_show' ) && xn_filled( $s, 'title' ) ) {
            echo '<' . $title_tag . ' class="xn-h2 xn-disp">' . nl2br( esc_html( $s['title'] ) ) . '</' . $title_tag . '>';
        }
        if ( xn_on( $s, 'lead_show' ) ) {
            $lead = xn_wysiwyg( $s['lead'] ?? '' );
            if ( $lead !== '' ) echo '<div class="xn-lead">' . $lead . '</div>';
        }
    }

    /**
     * Tlačidlo: show + text + URL + štýl.
     * $url_desc — popis pod URL (napr. že prázdne = predaj podľa jazyka).
     */
    protected function xn_button_controls( $id, $label, $text, $style = 'primary', $url = '', $url_desc = '' ) {
        $this->xn_heading_control( $id . '_heading', $label );
        $this->xn_show_control( $id . '_show', 'Zobraziť tlačidlo' );
        $this->add_control( $id . '_text', [
            'label'     => 'Text',
            'type'      => Controls_Manager::TEXT,
            'default'   => $text,
            'condition' => [ $id . '_show' => 'yes' ],
        ]);
        $this->add_control( $id . '_url', [
            'label'       => 'Odkaz',
            'type'        => Controls_Manager::URL,
            'placeholder' => $url_desc ? 'prázdne = predaj vstupeniek (SK/EN)' : 'https://',
            'description' => $url_desc,
            'default'     => [ 'url' => $url ],
            'condition'   => [ $id . '_show' => 'yes' ],
        ]);
        $this->add_control( $id . '_style', [
            'label'     => 'Štýl',
            'type'      => Controls_Manager::SELECT,
            'default'   => $style,
            'options'   => [ 'primary' => 'Plné (biele / čierne)', 'grad' => 'Gradientový obrys' ],
            'condition' => [ $id . '_show' => 'yes' ],
        ]);
    }

    protected function xn_tickets_desc() {
        return 'Prázdne = checkout tickets.sgf.sk podľa jazyka stránky (SK: tickets.sgf.sk/sk/?add-to-cart=5964, EN: tickets.sgf.sk/?add-to-cart=5964). Externé linky sa otvárajú v novom okne automaticky.';
    }

    protected function xn_render_button( $s, $id, $fallback = '', $extra = [] ) {
        if ( ! xn_on( $s, $id . '_show' ) || ! xn_filled( $s, $id . '_text' ) ) return;
        xn_button( array_merge( [
            'text'     => $s[ $id . '_text' ],
            'url'      => $s[ $id . '_url' ] ?? '',
            'fallback' => $fallback,
            'style'    => $s[ $id . '_style' ] ?? 'primary',
        ], $extra ) );
    }

    protected function xn_media_controls( $id, $label, $default_url, $width_selector, $alt = null ) {
        $this->add_control( $id, [
            'label'   => $label,
            'type'    => Controls_Manager::MEDIA,
            'default' => [ 'url' => $default_url ],
        ]);
        $this->add_responsive_control( $id . '_width', [
            'label'      => 'Šírka',
            'type'       => Controls_Manager::SLIDER,
            'size_units' => [ 'px', '%' ],
            'range'      => [ 'px' => [ 'min' => 20, 'max' => 1600 ], '%' => [ 'min' => 10, 'max' => 150 ] ],
            'selectors'  => [ $width_selector => 'width: {{SIZE}}{{UNIT}}; height: auto;' ],
        ]);
        if ( $alt !== null ) {
            $this->add_control( $id . '_alt', [
                'label'   => 'Alt text',
                'type'    => Controls_Manager::TEXT,
                'default' => $alt,
            ]);
        }
    }

    // ── STYLE ────────────────────────────────────────────────────────

    protected function xn_style_section( $selector = '{{WRAPPER}} .xn-section', $label = 'Sekcia' ) {
        $this->start_controls_section('style_section', [
            'label' => $label,
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_group_control( Group_Control_Background::get_type(), [
            'name'     => 'section_bg',
            'types'    => [ 'classic', 'gradient' ],
            'selector' => $selector,
        ]);

        $this->add_responsive_control('padding_top', [
            'label'       => 'Padding — hore',
            'type'        => Controls_Manager::SLIDER,
            'size_units'  => [ 'px' ],
            'range'       => [ 'px' => [ 'min' => 0, 'max' => 200 ] ],
            'description' => 'Prázdne = podľa dizajnu.',
            'selectors'   => [ $selector => 'padding-top: {{SIZE}}{{UNIT}};' ],
        ]);
        $this->add_responsive_control('padding_bottom', [
            'label'      => 'Padding — dole',
            'type'       => Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 200 ] ],
            'selectors'  => [ $selector => 'padding-bottom: {{SIZE}}{{UNIT}};' ],
        ]);
        $this->add_responsive_control('min_height', [
            'label'      => 'Min. výška',
            'type'       => Controls_Manager::SLIDER,
            'size_units' => [ 'px', 'vh' ],
            'range'      => [ 'px' => [ 'min' => 0, 'max' => 1400 ], 'vh' => [ 'min' => 0, 'max' => 100 ] ],
            'selectors'  => [ $selector => 'min-height: {{SIZE}}{{UNIT}};' ],
        ]);

        $this->end_controls_section();
    }

    /**
     * $items = [ [ id, label, selector, mode ] ] — mode: 'color' (default) | 'gradient' | 'none'
     */
    protected function xn_style_texts( array $items, $label = 'Typografia a farby' ) {
        $this->start_controls_section('style_texts', [
            'label' => $label,
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        foreach ( $items as $item ) {
            list( $id, $item_label, $selector ) = $item;
            $mode = $item[3] ?? 'color';

            $this->xn_heading_control( $id . '_style_heading', $item_label );

            $this->add_group_control( Group_Control_Typography::get_type(), [
                'name'     => $id . '_typo',
                'selector' => $selector,
            ]);

            if ( $mode === 'color' ) {
                $this->add_control( $id . '_color', [
                    'label'     => 'Farba',
                    'type'      => Controls_Manager::COLOR,
                    'selectors' => [ $selector => 'color: {{VALUE}};' ],
                ]);
            }

            if ( $mode === 'gradient' ) {
                $this->add_control( $id . '_grad', [
                    'label'        => 'Gradient textu',
                    'type'         => Controls_Manager::POPOVER_TOGGLE,
                    'label_off'    => 'Brand',
                    'label_on'     => 'Vlastný',
                    'return_value' => 'yes',
                    'selectors'    => [ $selector => 'background-image: linear-gradient(90deg, var(--widget-title-from, #ff5e5d) 0%, var(--widget-title-to, #5762b3) 100%);' ],
                ]);
                $this->start_popover();
                $this->add_control( $id . '_grad_from', [
                    'label'     => 'Farba gradientu — začiatok',
                    'type'      => Controls_Manager::COLOR,
                    'selectors' => [ $selector => '--widget-title-from: {{VALUE}};' ],
                ]);
                $this->add_control( $id . '_grad_to', [
                    'label'     => 'Farba gradientu — koniec',
                    'type'      => Controls_Manager::COLOR,
                    'selectors' => [ $selector => '--widget-title-to: {{VALUE}};' ],
                ]);
                $this->end_popover();
            }
        }

        $this->end_controls_section();
    }

    /** $items = [ [ id, label, selector ] ] */
    protected function xn_style_cards( array $items, $label = 'Karty / boxy' ) {
        $this->start_controls_section('style_cards', [
            'label' => $label,
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        foreach ( $items as list( $id, $item_label, $selector ) ) {
            $this->add_control( $id . '_box', [
                'label'        => $item_label,
                'type'         => Controls_Manager::POPOVER_TOGGLE,
                'label_off'    => 'Default',
                'label_on'     => 'Custom',
                'return_value' => 'yes',
            ]);
            $this->start_popover();
            $this->add_group_control( Group_Control_Background::get_type(), [
                'name'     => $id . '_bg',
                'types'    => [ 'classic', 'gradient' ],
                'selector' => $selector,
            ]);
            $this->add_group_control( Group_Control_Border::get_type(), [
                'name'     => $id . '_border',
                'selector' => $selector,
            ]);
            $this->add_responsive_control( $id . '_radius', [
                'label'      => 'Border radius',
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%' ],
                'selectors'  => [ $selector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
            ]);
            $this->end_popover();

            $this->add_group_control( Group_Control_Box_Shadow::get_type(), [
                'name'     => $id . '_shadow',
                'label'    => $item_label . ' — tieň',
                'selector' => $selector,
            ]);
        }

        $this->end_controls_section();
    }

    protected function xn_style_buttons( $selector = '{{WRAPPER}} .xn-btn', $label = 'Tlačidlá' ) {
        $this->start_controls_section('style_buttons', [
            'label' => $label,
            'tab'   => Controls_Manager::TAB_STYLE,
        ]);

        $this->add_group_control( Group_Control_Typography::get_type(), [
            'name'     => 'btn_typo',
            'selector' => $selector,
        ]);

        $this->start_controls_tabs('tabs_btn_style');

        $this->start_controls_tab('tab_btn_normal', [ 'label' => 'Normal' ]);
        $this->add_control('btn_bg', [
            'label'     => 'Pozadie',
            'type'      => Controls_Manager::COLOR,
            'selectors' => [ $selector => 'background: {{VALUE}};' ],
        ]);
        $this->add_control('btn_color', [
            'label'     => 'Text',
            'type'      => Controls_Manager::COLOR,
            'selectors' => [ $selector => 'color: {{VALUE}} !important;' ],
        ]);
        $this->end_controls_tab();

        $this->start_controls_tab('tab_btn_hover', [ 'label' => 'Hover' ]);
        $this->add_control('btn_bg_hover', [
            'label'     => 'Pozadie (hover)',
            'type'      => Controls_Manager::COLOR,
            'selectors' => [ $selector . ':hover' => 'background: {{VALUE}};' ],
        ]);
        $this->add_control('btn_color_hover', [
            'label'     => 'Text (hover)',
            'type'      => Controls_Manager::COLOR,
            'selectors' => [ $selector . ':hover' => 'color: {{VALUE}} !important;' ],
        ]);
        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_group_control( Group_Control_Border::get_type(), [
            'name'      => 'btn_border',
            'selector'  => $selector,
            'separator' => 'before',
        ]);
        $this->add_responsive_control('btn_radius', [
            'label'      => 'Border radius',
            'type'       => Controls_Manager::DIMENSIONS,
            'size_units' => [ 'px', '%' ],
            'selectors'  => [ $selector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
        ]);

        $this->end_controls_section();
    }
}
