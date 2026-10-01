<?php
// $settings dostupný z render() scope
$mode     = ( $settings['default_mode'] ?? 'live' ) === 'stream' ? 'stream' : 'live';
$filled   = function( $u ) { return is_array( $u ) && trim( $u['url'] ?? '' ) !== ''; };
$fallback = $filled( $settings['tickets_url'] ?? null ) ? $settings['tickets_url'] : xn_tickets_url();
$fb_strm  = $filled( $settings['tickets_url_stream'] ?? null ) ? $settings['tickets_url_stream'] : null;
$href     = function( $u ) { return is_array( $u ) ? trim( $u['url'] ?? '' ) : (string) $u; };
$rib      = xn_asset( 'img/xn-ribbons.png' );
$fine     = xn_on( $settings, 'fine_show' ) ? xn_wysiwyg( $settings['fine'] ?? '' ) : '';
$modes    = [ 'live', 'stream' ];

$dual = function( $live, $stream ) {
    $live   = trim( (string) $live );
    $stream = trim( (string) $stream );
    if ( $live === $stream ) return esc_html( $live );
    return '<span class="only-live">' . esc_html( $live ) . '</span><span class="only-stream">' . esc_html( $stream ) . '</span>';
};
?>
<section id="<?php echo esc_attr( $settings['anchor_id'] ?? 'vstupenky' ); ?>" class="custom-pricing xn-block xn-section" data-widget="pricing" data-mode="<?php echo esc_attr( $mode ); ?>">
    <?php if ( xn_on( $settings, 'line_show' ) ) : ?>
        <div class="xn-grad-line pricing__line"></div>
    <?php endif; ?>
    <?php if ( xn_on( $settings, 'parallax_show' ) ) : ?>
        <img class="pricing__prx pricing__prx--a" src="<?php echo esc_url( $rib ); ?>" alt="" data-speed="0.18" />
        <img class="pricing__prx pricing__prx--b" src="<?php echo esc_url( $rib ); ?>" alt="" data-speed="-0.12" data-flip="1" />
    <?php endif; ?>

    <div class="xn-wrap xn-z">
        <?php if ( xn_on( $settings, 'eyebrow_show' ) && xn_filled( $settings, 'eyebrow' ) ) : ?>
            <div class="xn-eyebrow"><?php echo esc_html( $settings['eyebrow'] ); ?></div>
        <?php endif; ?>

        <div class="pricing__head">
            <?php if ( xn_on( $settings, 'title_show' ) && xn_filled( $settings, 'title' ) ) : ?>
                <h2 class="xn-h2 xn-disp"><?php echo nl2br( esc_html( $settings['title'] ) ); ?></h2>
            <?php endif; ?>
            <?php if ( xn_on( $settings, 'badge_show' ) ) : ?>
                <div class="pricing__badge" aria-label="<?php echo esc_attr( trim( ( $settings['badge_label'] ?? '' ) . ' ' . ( $settings['badge_prefix'] ?? '' ) . ' ' . ( $settings['badge_date'] ?? '' ) ) ); ?>">
                    <span class="pricing__badge-t"><?php echo esc_html( $settings['badge_label'] ?? '' ); ?></span>
                    <span class="pricing__badge-d"><?php echo esc_html( $settings['badge_prefix'] ?? '' ); ?><b><?php echo esc_html( $settings['badge_date'] ?? '' ); ?></b></span>
                </div>
            <?php endif; ?>
        </div>

        <?php if ( xn_on( $settings, 'lead_show' ) && ( $lead = xn_wysiwyg( $settings['lead'] ?? '' ) ) !== '' ) : ?>
            <div class="xn-lead"><?php echo $lead; ?></div>
        <?php endif; ?>

        <div class="pricing__tabs" role="tablist">
            <?php foreach ( $modes as $m ) : $on = $m === $mode; ?>
                <button type="button" class="pricing__tab<?php echo $on ? ' is-on' : ''; ?>" data-mode="<?php echo esc_attr( $m ); ?>" role="tab" aria-selected="<?php echo $on ? 'true' : 'false'; ?>">
                    <i></i>
                    <span><b><?php echo esc_html( $settings[ 'tab_' . $m . '_title' ] ?? '' ); ?></b><small><?php echo esc_html( $settings[ 'tab_' . $m . '_sub' ] ?? '' ); ?></small></span>
                    <em><?php echo esc_html( $settings[ 'tab_' . $m . '_meta' ] ?? '' ); ?></em>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="pricing__plans">
            <?php foreach ( $settings['plans'] ?? [] as $p ) :
                $features = xn_lines( $p['features'] ?? '' );
                $url      = $filled( $p['btn_url'] ?? null ) ? $p['btn_url'] : $fallback;
                $url_strm = $filled( $p['btn_url_stream'] ?? null ) ? $p['btn_url_stream'] : ( $fb_strm ?: $url ); ?>
                <article class="pricing__plan<?php echo xn_on( $p, 'best' ) ? ' is-best' : ''; ?> elementor-repeater-item-<?php echo esc_attr( $p['_id'] ?? '' ); ?>">
                    <?php if ( xn_filled( $p, 'badge' ) ) : ?>
                        <span class="pricing__plan-badge"><?php echo esc_html( $p['badge'] ); ?></span>
                    <?php endif; ?>
                    <span class="pricing__mode"><?php echo $dual( $settings['mode_live_label'] ?? '', $settings['mode_stream_label'] ?? '' ); ?></span>
                    <?php if ( xn_filled( $p, 'k' ) ) : ?>
                        <div class="pricing__k"><?php echo esc_html( $p['k'] ); ?></div>
                    <?php endif; ?>
                    <h3 class="pricing__title xn-disp"><?php echo $dual( $p['title_live'] ?? '', $p['title_stream'] ?? '' ); ?></h3>
                    <div class="pricing__sub"><?php echo $dual( $p['sub_live'] ?? '', $p['sub_stream'] ?? '' ); ?></div>

                    <div class="pricing__price">
                        <?php if ( xn_filled( $p, 'eb_label' ) ) : ?>
                            <span class="pricing__eb"><?php echo esc_html( $p['eb_label'] ); ?></span>
                        <?php endif; ?>
                        <b class="xn-grad"><?php echo esc_html( $p['price'] ?? '' ); ?></b>
                        <?php if ( xn_filled( $p, 'was_price' ) ) : ?>
                            <span class="pricing__was"><?php echo esc_html( $p['was_label'] ?? '' ); ?> <s><?php echo esc_html( $p['was_price'] ); ?></s></span>
                        <?php endif; ?>
                    </div>

                    <?php if ( xn_filled( $p, 'per' ) ) : ?>
                        <div class="pricing__per"><?php echo xn_inline( $p['per'] ); ?></div>
                    <?php endif; ?>

                    <?php if ( $features ) : ?>
                        <ul class="pricing__list">
                            <?php foreach ( $features as $f ) : list( $fl, $fs ) = xn_cols( $f, 2 ); ?>
                                <li><?php echo $dual( $fl, $fs !== '' ? $fs : $fl ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ( $href( $url ) === $href( $url_strm ) ) :
                        xn_button( [
                            'html'  => $dual( $p['btn_live'] ?? '', $p['btn_stream'] ?? '' ),
                            'url'   => $url,
                            'style' => $p['btn_style'] ?? 'grad',
                        ] );
                    else :
                        xn_button( [
                            'text'  => trim( (string) ( $p['btn_live'] ?? '' ) ),
                            'url'   => $url,
                            'style' => $p['btn_style'] ?? 'grad',
                            'class' => 'only-live',
                        ] );
                        xn_button( [
                            'text'  => trim( (string) ( $p['btn_stream'] ?? '' ) ),
                            'url'   => $url_strm,
                            'style' => $p['btn_style'] ?? 'grad',
                            'class' => 'only-stream',
                        ] );
                    endif; ?>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ( $fine !== '' ) : ?>
            <div class="pricing__fine"><?php echo $fine; ?></div>
        <?php endif; ?>
    </div>
</section>
