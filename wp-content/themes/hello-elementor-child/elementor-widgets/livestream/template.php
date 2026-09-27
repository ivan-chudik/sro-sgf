<?php
// $settings dostupný z render() scope
$steps = xn_on( $settings, 'steps_show' ) ? ( $settings['steps'] ?? [] ) : [];
$live  = $settings['live_label'] ?? '';

$ctl = function( $title, $sub ) {
    if ( trim( $title . $sub ) === '' ) return;
    echo '<div class="livestream__ctl"><div class="livestream__tl">' . esc_html( $title );
    if ( trim( $sub ) !== '' ) echo '<small>' . esc_html( $sub ) . '</small>';
    echo '</div><div class="livestream__pb"></div></div>';
};
?>
<section id="<?php echo esc_attr( $settings['anchor_id'] ?? 'livestream' ); ?>" class="custom-livestream xn-block xn-section" data-widget="livestream">
    <div class="xn-wrap livestream__grid<?php echo xn_on( $settings, 'mock_show' ) ? '' : ' is-no-mock'; ?>">

        <?php if ( xn_on( $settings, 'mock_show' ) ) : ?>
            <div class="livestream__mock" role="img" aria-label="<?php echo esc_attr( $settings['mock_label'] ?? '' ); ?>">
                <div class="livestream__lap">
                    <div class="livestream__lid">
                        <i class="livestream__cam"></i>
                        <div class="livestream__scr">
                            <div class="livestream__bar"><i></i><i></i><?php echo esc_html( $settings['bar_url'] ?? '' ); ?><span><?php echo esc_html( $settings['bar_quality'] ?? '' ); ?></span></div>
                            <?php if ( $live !== '' ) : ?><span class="livestream__badge"><?php echo esc_html( $live ); ?></span><?php endif; ?>
                            <?php echo xn_img( $settings['lap_image'] ?? [], '', '' ); ?>
                            <?php $ctl( $settings['lap_title'] ?? '', $settings['lap_sub'] ?? '' ); ?>
                        </div>
                    </div>
                    <div class="livestream__base"></div>
                </div>
                <div class="livestream__ph">
                    <div class="livestream__scr">
                        <i class="livestream__notch"></i>
                        <?php if ( $live !== '' ) : ?><span class="livestream__badge"><?php echo esc_html( $live ); ?></span><?php endif; ?>
                        <?php echo xn_img( $settings['ph_image'] ?? [], '', '' ); ?>
                        <?php $ctl( $settings['ph_title'] ?? '', $settings['ph_sub'] ?? '' ); ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="livestream__copy">
            <?php $this->xn_render_intro( $settings ); ?>

            <?php if ( $steps ) : ?>
                <ol class="livestream__steps">
                    <?php foreach ( $steps as $s ) : ?>
                        <li><div><?php if ( xn_filled( $s, 'title' ) ) : ?><b><?php echo esc_html( $s['title'] ); ?></b><?php endif; ?><?php echo esc_html( $s['text'] ?? '' ); ?></div></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

            <div class="xn-cta-row">
                <?php $this->xn_render_button( $settings, 'btn1', xn_tickets_url() ); ?>
                <?php $this->xn_render_button( $settings, 'btn2', 'https://stream.sgf.sk/' ); ?>
            </div>
        </div>

    </div>
</section>
