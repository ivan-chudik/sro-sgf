<?php
// $settings dostupný z render() scope
$logos = $settings['logos'] ?? [];
if ( ! $logos ) return;
?>
<section class="custom-partners xn-block xn-section" data-widget="partners">
    <div class="xn-wrap">
        <?php if ( xn_on( $settings, 'label_show' ) && xn_filled( $settings, 'label' ) ) : ?>
            <div class="partners__label"><?php echo esc_html( $settings['label'] ); ?></div>
        <?php endif; ?>
        <div class="partners__row">
            <?php foreach ( $logos as $logo ) :
                $img   = xn_img( $logo['image'] ?? [], '', $logo['alt'] ?? '', [ 'loading' => 'lazy' ] );
                $link  = xn_link_attrs( $logo['link'] ?? '' );
                $class = 'partners__item elementor-repeater-item-' . ( $logo['_id'] ?? '' );
                if ( ! $img ) continue; ?>
                <?php if ( $link ) : ?>
                    <a class="<?php echo esc_attr( $class ); ?>"<?php echo $link; ?>><?php echo $img; ?></a>
                <?php else : ?>
                    <span class="<?php echo esc_attr( $class ); ?>"><?php echo $img; ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>
