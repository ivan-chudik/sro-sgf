<?php
// $settings dostupný z render() scope
$rib    = xn_asset( 'img/xn-ribbons.png' );
$target = $settings['countdown_target'] ?? '';
$ts     = $target ? strtotime( $target ) : false;
$days   = $ts ? max( 0, (int) ceil( ( $ts - time() ) / DAY_IN_SECONDS ) ) : '—';
?>
<section id="<?php echo esc_attr( $settings['anchor_id'] ?? 'top' ); ?>" class="custom-hero xn-block xn-section" data-widget="hero">
    <?php if ( xn_on( $settings, 'ribbons_show' ) ) : ?>
        <img class="xn-rib hero__rib-bg" src="<?php echo esc_url( $rib ); ?>" alt="" />
    <?php endif; ?>

    <div class="xn-wrap xn-z hero__grid">

        <div class="hero__copy">
            <?php if ( xn_on( $settings, 'date_show' ) && xn_filled( $settings, 'date_text' ) ) : ?>
                <span class="hero__date"><i></i><?php echo esc_html( $settings['date_text'] ); ?></span>
            <?php endif; ?>
            <?php if ( xn_filled( $settings, 'title' ) ) : ?>
                <h1 class="hero__title xn-disp xn-grad"><?php echo esc_html( $settings['title'] ); ?></h1>
            <?php endif; ?>
            <?php if ( xn_on( $settings, 'subtitle_show' ) && xn_filled( $settings, 'subtitle' ) ) : ?>
                <div class="hero__sub xn-sub xn-grad"><?php echo esc_html( $settings['subtitle'] ); ?></div>
            <?php endif; ?>
            <?php if ( xn_on( $settings, 'claim_show' ) && xn_filled( $settings, 'claim' ) ) : ?>
                <p class="hero__claim"><?php echo xn_inline( $settings['claim'] ); ?></p>
            <?php endif; ?>
        </div>

        <?php if ( xn_on( $settings, 'image_show' ) ) : ?>
            <div class="hero__art">
                <?php if ( xn_on( $settings, 'ribbons_show' ) ) : ?>
                    <img class="xn-rib hero__rib-art" src="<?php echo esc_url( $rib ); ?>" alt="" />
                <?php endif; ?>
                <?php echo xn_img( $settings['image'] ?? [], 'hero__gym', $settings['image_alt'] ?? '' ); ?>
            </div>
        <?php endif; ?>

        <div class="xn-cta-row hero__cta">
            <?php
            foreach ( [ 'btn1', 'btn2' ] as $b ) {
                $mode = $settings[ $b . '_mode' ] ?? '';
                $this->xn_render_button( $settings, $b, '#', [
                    'size'  => 'lg',
                    'attrs' => $mode ? [ 'data-pricing-mode' => $mode ] : [],
                ] );
            }
            ?>
        </div>

        <?php if ( xn_on( $settings, 'countdown_show' ) ) : ?>
            <div class="hero__soon">
                <b class="hero__cd-num" data-countdown="<?php echo esc_attr( $target ); ?>"><?php echo esc_html( $days ); ?></b>
                <div class="hero__cd-text">
                    <?php if ( xn_filled( $settings, 'countdown_label' ) ) : ?>
                        <span class="hero__cd-label"><?php echo esc_html( $settings['countdown_label'] ); ?></span>
                    <?php endif; ?>
                    <?php if ( xn_filled( $settings, 'countdown_note' ) ) : ?>
                        <small class="hero__cd-note"><?php echo xn_inline( $settings['countdown_note'] ); ?></small>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>
</section>
