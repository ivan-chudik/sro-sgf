<?php
// $settings dostupný z render() scope
$is_editor = class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor->is_edit_mode();
$lang      = $is_editor ? ( $settings['preview_lang'] ?? 'sk' ) : xn_lang();
$lang      = $lang === 'en' ? 'en' : 'sk';
$text      = xn_wysiwyg( $settings[ $lang . '_text' ] ?? '' );
$rib       = xn_asset( 'img/xn-ribbons.png' );
?>
<section class="custom-not-found xn-block xn-section" data-widget="not-found" lang="<?php echo esc_attr( $lang ); ?>">
    <?php if ( xn_on( $settings, 'ribbons_show' ) ) : ?>
        <img class="xn-rib not-found__rib not-found__rib--a" src="<?php echo esc_url( $rib ); ?>" alt="" />
        <img class="xn-rib not-found__rib not-found__rib--b" src="<?php echo esc_url( $rib ); ?>" alt="" />
    <?php endif; ?>

    <div class="xn-wrap xn-z not-found__inner">
        <?php if ( xn_on( $settings, 'logo_show' ) ) : ?>
            <a class="not-found__home" href="<?php echo esc_url( xn_home_url( $lang ) ); ?>">
                <?php echo xn_img( $settings['logo'] ?? [], 'not-found__logo', $settings['logo_alt'] ?? '' ); ?>
            </a>
        <?php endif; ?>

        <?php if ( xn_filled( $settings, 'code' ) ) : ?>
            <div class="not-found__code xn-disp xn-grad"><?php echo esc_html( $settings['code'] ); ?></div>
        <?php endif; ?>

        <?php if ( xn_filled( $settings, $lang . '_title' ) ) : ?>
            <h1 class="not-found__title xn-disp"><?php echo esc_html( $settings[ $lang . '_title' ] ); ?></h1>
        <?php endif; ?>

        <?php if ( $text !== '' ) : ?>
            <div class="not-found__text"><?php echo $text; ?></div>
        <?php endif; ?>

        <div class="xn-cta-row not-found__cta">
            <?php
            xn_button( [ 'text' => $settings[ $lang . '_btn1' ] ?? '', 'url' => xn_home_url( $lang ), 'style' => 'primary' ] );
            if ( xn_on( $settings, 'btn2_show' ) ) {
                xn_button( [ 'text' => $settings[ $lang . '_btn2' ] ?? '', 'url' => $lang === 'en' ? XN_TICKETS_EN : XN_TICKETS_SK, 'style' => 'grad' ] );
            }
            ?>
        </div>
    </div>
</section>
