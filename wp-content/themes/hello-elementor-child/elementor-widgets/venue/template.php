<?php
// $settings dostupný z render() scope
$info = xn_on( $settings, 'info_show' ) ? ( $settings['info'] ?? [] ) : [];
?>
<section id="<?php echo esc_attr( $settings['anchor_id'] ?? 'miesto' ); ?>" class="custom-venue xn-block xn-section" data-widget="venue">
    <div class="xn-wrap venue__grid<?php echo xn_on( $settings, 'photo_show' ) ? '' : ' is-no-photo'; ?>">
        <div class="venue__copy">
            <?php $this->xn_render_intro( $settings ); ?>

            <?php if ( xn_on( $settings, 'addr_show' ) ) : ?>
                <div class="venue__addr">
                    <i></i>
                    <div>
                        <?php if ( xn_filled( $settings, 'addr_name' ) ) : ?><b><?php echo esc_html( $settings['addr_name'] ); ?></b><?php endif; ?>
                        <?php if ( xn_filled( $settings, 'addr_street' ) ) : ?><span><?php echo esc_html( $settings['addr_street'] ); ?></span><?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( $info ) : ?>
                <div class="venue__info">
                    <?php foreach ( $info as $cell ) :
                        $text = xn_wysiwyg( $cell['text'] ?? '' ); ?>
                        <div class="venue__cell">
                            <?php if ( xn_filled( $cell, 'label' ) ) : ?>
                                <div class="venue__info-label"><?php echo esc_html( $cell['label'] ); ?></div>
                            <?php endif; ?>
                            <?php if ( $text !== '' ) : ?>
                                <div class="venue__info-text"><?php echo $text; ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="xn-cta-row">
                <?php $this->xn_render_button( $settings, 'btn1', xn_tickets_url() ); ?>
                <?php $this->xn_render_button( $settings, 'btn2' ); ?>
            </div>
        </div>

        <?php if ( xn_on( $settings, 'photo_show' ) ) : ?>
            <?php echo xn_img( $settings['photo'] ?? [], 'venue__photo', $settings['photo_alt'] ?? '', [ 'loading' => 'lazy' ] ); ?>
        <?php endif; ?>
    </div>
</section>
