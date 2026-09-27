<?php
// $settings dostupný z render() scope
$lang      = ( $settings['lang_active'] ?? 'auto' ) === 'auto' ? xn_lang() : $settings['lang_active'];
$home      = ( $lang === 'en' ? ( $settings['lang_en_url']['url'] ?? '' ) : ( $settings['lang_sk_url']['url'] ?? '' ) ) ?: home_url( '/' );
$brand_url = ( $settings['brand_url']['url'] ?? '' ) ?: $home;
$nav_html  = xn_on( $settings, 'nav_show' ) ? xn_render_template( $settings['nav_template'] ?? 0 ) : '';
$is_editor = class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor->is_edit_mode();
?>
<header class="custom-header xn-block" data-widget="header">
    <div class="header__bar">
        <div class="xn-wrap header__inner">

            <a class="header__brand" href="<?php echo esc_url( $brand_url ); ?>">
                <?php echo xn_img( $settings['logo'] ?? [], 'header__logo', $settings['logo_alt'] ?? '' ); ?>
                <?php if ( xn_filled( $settings, 'brand_text' ) ) : ?>
                    <span class="header__brand-text xn-disp"><?php echo esc_html( $settings['brand_text'] ); ?></span>
                <?php endif; ?>
            </a>

            <?php if ( $nav_html ) : ?>
                <nav class="header__nav"><?php echo $nav_html; ?></nav>
            <?php elseif ( $is_editor && xn_on( $settings, 'nav_show' ) ) : ?>
                <div class="header__nav header__nav--empty">Vyber šablónu s Nav Menu</div>
            <?php endif; ?>

            <?php if ( xn_on( $settings, 'lang_show' ) ) : ?>
                <div class="header__lang">
                    <a class="<?php echo $lang === 'sk' ? 'is-on' : ''; ?>" href="<?php echo esc_url( $settings['lang_sk_url']['url'] ?? XN_HOME_SK ); ?>" hreflang="sk"><?php echo esc_html( $settings['lang_sk_label'] ?? 'SK' ); ?></a>
                    <a class="<?php echo $lang === 'en' ? 'is-on' : ''; ?>" href="<?php echo esc_url( $settings['lang_en_url']['url'] ?? XN_HOME_EN ); ?>" hreflang="en"><?php echo esc_html( $settings['lang_en_label'] ?? 'EN' ); ?></a>
                </div>
            <?php endif; ?>

            <?php $this->xn_render_button( $settings, 'cta', xn_tickets_url(), [ 'class' => 'header__cta' ] ); ?>

        </div>
    </div>
</header>
