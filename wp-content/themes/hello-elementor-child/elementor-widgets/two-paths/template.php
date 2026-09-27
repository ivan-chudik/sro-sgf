<?php
// $settings dostupný z render() scope
$cards = [ 'c1' => 'light', 'c2' => 'dark' ];
?>
<section id="<?php echo esc_attr( $settings['anchor_id'] ?? 'cesty' ); ?>" class="custom-two-paths xn-block xn-section xn-light" data-widget="two-paths">
    <div class="xn-wrap">
        <?php $this->xn_render_intro( $settings ); ?>

        <div class="two-paths__grid">
            <?php foreach ( $cards as $c => $variant ) :
                if ( ! xn_on( $settings, $c . '_show' ) ) continue;
                $text     = xn_wysiwyg( $settings[ $c . '_text' ] ?? '' );
                $features = xn_lines( $settings[ $c . '_features' ] ?? '' ); ?>
                <article class="two-paths__card two-paths__card--<?php echo esc_attr( $variant ); ?>">
                    <?php if ( $variant === 'dark' ) : ?>
                        <img class="xn-rib two-paths__rib" src="<?php echo esc_url( xn_asset( 'img/xn-ribbons.png' ) ); ?>" alt="" />
                    <?php endif; ?>

                    <?php if ( xn_filled( $settings, $c . '_tag' ) ) : ?>
                        <span class="two-paths__tag xn-z"><?php echo esc_html( $settings[ $c . '_tag' ] ); ?></span>
                    <?php endif; ?>

                    <?php if ( xn_filled( $settings, $c . '_title' ) ) : ?>
                        <h3 class="two-paths__title xn-disp xn-z"><?php echo esc_html( $settings[ $c . '_title' ] ); ?></h3>
                    <?php endif; ?>

                    <?php if ( $text !== '' ) : ?>
                        <div class="two-paths__text xn-z"><?php echo $text; ?></div>
                    <?php endif; ?>

                    <?php if ( $features ) : ?>
                        <ul class="two-paths__list xn-z">
                            <?php foreach ( $features as $f ) : ?>
                                <li><?php echo esc_html( $f ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ( xn_on( $settings, $c . '_price_show' ) ) : ?>
                        <div class="two-paths__price xn-z">
                            <?php if ( xn_filled( $settings, $c . '_price_from' ) ) : ?><span><?php echo esc_html( $settings[ $c . '_price_from' ] ); ?></span><?php endif; ?>
                            <b><?php echo esc_html( $settings[ $c . '_price' ] ?? '' ); ?></b>
                            <?php if ( xn_filled( $settings, $c . '_price_unit' ) ) : ?><span><?php echo esc_html( $settings[ $c . '_price_unit' ] ); ?></span><?php endif; ?>
                            <?php if ( xn_on( $settings, $c . '_eb_show' ) ) : ?>
                                <span class="two-paths__eb"><span class="two-paths__eb-t"><?php echo esc_html( $settings[ $c . '_eb_label' ] ?? '' ); ?></span><span class="two-paths__eb-d"><?php echo esc_html( $settings[ $c . '_eb_date' ] ?? '' ); ?></span></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="two-paths__foot xn-z">
                        <?php $this->xn_render_button( $settings, $c . '_btn', xn_tickets_url() ); ?>
                        <?php if ( xn_filled( $settings, $c . '_foot' ) ) : ?>
                            <small><?php echo esc_html( $settings[ $c . '_foot' ] ); ?></small>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
