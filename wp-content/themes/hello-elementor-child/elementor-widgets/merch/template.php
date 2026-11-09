<?php
// $settings dostupný z render() scope
$poster_url = $settings['poster']['url'] ?? '';
$mobile_url = $settings['poster_mobile']['url'] ?? '';
$poster_alt = $settings['poster_alt'] ?? '';
$lightbox   = xn_on( $settings, 'poster_lightbox' ) && $poster_url;
$items      = xn_on( $settings, 'items_show' ) ? array_filter( (array) ( $settings['items'] ?? [] ), function( $i ) { return trim( $i['text'] ?? '' ) !== ''; } ) : [];
$note       = xn_on( $settings, 'note_show' ) ? xn_wysiwyg( $settings['note'] ?? '' ) : '';
?>
<section id="<?php echo esc_attr( $settings['anchor_id'] ?? 'merch' ); ?>" class="custom-merch xn-block xn-section" data-widget="merch">
    <div class="xn-wrap">
        <div class="merch__head"><?php $this->xn_render_intro( $settings ); ?></div>

        <?php if ( xn_on( $settings, 'badge_show' ) && xn_filled( $settings, 'badge' ) ) : ?>
            <div class="merch__badge-row"><span class="merch__badge"><?php echo esc_html( $settings['badge'] ); ?></span></div>
        <?php endif; ?>

        <?php if ( xn_on( $settings, 'poster_show' ) && $poster_url ) : ?>
            <figure class="merch__poster">
                <?php if ( $lightbox ) : ?>
                    <a class="merch__zoom" href="<?php echo esc_url( $poster_url ); ?>" data-elementor-open-lightbox="yes" data-elementor-lightbox-slideshow="merch-<?php echo esc_attr( $this->get_id() ); ?>" aria-label="<?php echo esc_attr( 'Zväčšiť: ' . $poster_alt ); ?>">
                <?php endif; ?>
                <picture>
                    <?php if ( $mobile_url ) : ?>
                        <source media="(max-width: 767px)" srcset="<?php echo esc_url( $mobile_url ); ?>" />
                    <?php endif; ?>
                    <img class="merch__img" src="<?php echo esc_url( $poster_url ); ?>" alt="<?php echo esc_attr( $poster_alt ); ?>" loading="lazy" decoding="async" />
                </picture>
                <?php if ( $lightbox ) : ?></a><?php endif; ?>
            </figure>
        <?php endif; ?>

        <?php if ( $items ) : ?>
            <ul class="merch__items">
                <?php foreach ( $items as $item ) : ?>
                    <li class="merch__item"><?php echo esc_html( $item['text'] ); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ( xn_on( $settings, 'btn1_show' ) ) : ?>
            <div class="xn-cta-row merch__cta"><?php $this->xn_render_button( $settings, 'btn1', self::FORM_URL, [ 'size' => 'lg', 'attrs' => [ 'data-merch-cta' => '1' ] ] ); ?></div>
        <?php endif; ?>

        <?php if ( $note !== '' ) : ?>
            <div class="merch__note"><?php echo $note; ?></div>
        <?php endif; ?>
    </div>
</section>
