<?php
// $settings dostupný z render() scope
$rib     = xn_asset( 'img/xn-ribbons.png' );
$text    = xn_on( $settings, 'text_show' ) ? xn_wysiwyg( $settings['text'] ?? '' ) : '';
$contact = xn_on( $settings, 'contact_show' ) ? xn_wysiwyg( $settings['contact'] ?? '' ) : '';
?>
<section class="custom-maintenance xn-block xn-section" data-widget="maintenance">
    <?php if ( xn_on( $settings, 'ribbons_show' ) ) : ?>
        <img class="xn-rib maintenance__rib maintenance__rib--a" src="<?php echo esc_url( $rib ); ?>" alt="" />
        <img class="xn-rib maintenance__rib maintenance__rib--b" src="<?php echo esc_url( $rib ); ?>" alt="" />
    <?php endif; ?>

    <div class="xn-wrap xn-z maintenance__inner">
        <?php if ( xn_on( $settings, 'logo_show' ) ) : ?>
            <?php echo xn_img( $settings['logo'] ?? [], 'maintenance__logo', $settings['logo_alt'] ?? '' ); ?>
        <?php endif; ?>

        <?php if ( xn_on( $settings, 'date_show' ) && xn_filled( $settings, 'date_text' ) ) : ?>
            <span class="maintenance__date"><i></i><?php echo esc_html( $settings['date_text'] ); ?></span>
        <?php endif; ?>

        <?php if ( xn_filled( $settings, 'title' ) ) : ?>
            <h1 class="maintenance__title xn-disp xn-grad"><?php echo esc_html( $settings['title'] ); ?></h1>
        <?php endif; ?>

        <?php if ( xn_on( $settings, 'subtitle_show' ) && xn_filled( $settings, 'subtitle' ) ) : ?>
            <div class="maintenance__sub xn-sub xn-grad"><?php echo esc_html( $settings['subtitle'] ); ?></div>
        <?php endif; ?>

        <?php if ( $text !== '' ) : ?>
            <div class="maintenance__text"><?php echo $text; ?></div>
        <?php endif; ?>

        <?php if ( $contact !== '' ) : ?>
            <div class="xn-grad-line maintenance__line"></div>
            <div class="maintenance__contact"><?php echo $contact; ?></div>
        <?php endif; ?>
    </div>
</section>
