<?php
// $settings dostupný z render() scope
$form_html = xn_render_template( $settings['form_template'] ?? 0 );
$note      = xn_on( $settings, 'note_show' ) ? xn_wysiwyg( $settings['note'] ?? '' ) : '';
$is_editor = class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor->is_edit_mode();
?>
<section class="custom-reminder xn-block xn-section" data-widget="reminder">
    <div class="xn-wrap">
        <div class="reminder__box">
            <?php if ( xn_on( $settings, 'ribbons_show' ) ) : ?>
                <img class="xn-rib reminder__rib" src="<?php echo esc_url( xn_asset( 'img/xn-ribbons.png' ) ); ?>" alt="" />
            <?php endif; ?>

            <div class="reminder__copy xn-z">
                <?php $this->xn_render_intro( $settings ); ?>
            </div>

            <div class="reminder__form xn-z">
                <?php if ( $form_html ) : ?>
                    <?php echo $form_html; ?>
                <?php elseif ( $is_editor ) : ?>
                    <div class="reminder__placeholder">Vyber šablónu s Elementor Form (Obsah → Formulár)</div>
                <?php endif; ?>
                <?php if ( $note !== '' ) : ?>
                    <div class="reminder__note"><?php echo $note; ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
