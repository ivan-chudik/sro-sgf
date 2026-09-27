<?php
// $settings dostupný z render() scope
$reasons = xn_on( $settings, 'reasons_show' ) ? ( $settings['reasons'] ?? [] ) : [];
$moments = xn_on( $settings, 'moments_show' ) ? ( $settings['moments'] ?? [] ) : [];
?>
<section id="<?php echo esc_attr( $settings['anchor_id'] ?? 'preco' ); ?>" class="custom-reasons xn-block xn-section" data-widget="reasons">
    <?php if ( xn_on( $settings, 'ribbons_show' ) ) : ?>
        <img class="xn-rib reasons__rib" src="<?php echo esc_url( xn_asset( 'img/xn-ribbons.png' ) ); ?>" alt="" />
    <?php endif; ?>

    <div class="xn-wrap xn-z">
        <?php $this->xn_render_intro( $settings ); ?>

        <?php if ( $reasons ) : ?>
            <div class="reasons__grid">
                <?php foreach ( $reasons as $r ) :
                    $text = xn_wysiwyg( $r['text'] ?? '' ); ?>
                    <div class="reasons__item">
                        <?php if ( xn_filled( $r, 'num' ) ) : ?>
                            <div class="reasons__num xn-grad"><?php echo esc_html( $r['num'] ); ?></div>
                        <?php endif; ?>
                        <?php if ( xn_filled( $r, 'title' ) ) : ?>
                            <h3 class="reasons__title xn-disp"><?php echo esc_html( $r['title'] ); ?></h3>
                        <?php endif; ?>
                        <?php if ( $text !== '' ) : ?>
                            <div class="reasons__text"><?php echo $text; ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( $moments ) : ?>
            <div class="reasons__moments">
                <?php foreach ( $moments as $m ) : ?>
                    <figure class="elementor-repeater-item-<?php echo esc_attr( $m['_id'] ?? '' ); ?>">
                        <?php echo xn_img( $m['image'] ?? [], '', $m['alt'] ?? '', [ 'loading' => 'lazy' ] ); ?>
                        <?php if ( xn_filled( $m, 'caption' ) ) : ?>
                            <figcaption><?php echo esc_html( $m['caption'] ); ?></figcaption>
                        <?php endif; ?>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( xn_on( $settings, 'credit_show' ) && xn_filled( $settings, 'credit' ) ) : ?>
            <p class="reasons__credit"><?php echo esc_html( $settings['credit'] ); ?></p>
        <?php endif; ?>
    </div>
</section>
