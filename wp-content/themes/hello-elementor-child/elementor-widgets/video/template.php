<?php
// $settings dostupný z render() scope
$yt_id  = xn_youtube_id( $settings['video_url'] ?? '' );
$poster = $settings['poster']['url'] ?? '';
$title  = $settings['video_title'] ?? '';
?>
<section id="<?php echo esc_attr( $settings['anchor_id'] ?? 'spot' ); ?>" class="custom-video xn-block xn-section" data-widget="video">
    <div class="xn-wrap">
        <div class="video__head"><?php $this->xn_render_intro( $settings ); ?></div>

        <?php if ( $yt_id ) : ?>
            <div class="video__frame" data-video-id="<?php echo esc_attr( $yt_id ); ?>" data-video-title="<?php echo esc_attr( $title ); ?>">
                <button type="button" class="video__trigger" aria-label="<?php echo esc_attr( trim( ( $settings['play_label'] ?? '' ) . ' — ' . $title, ' —' ) ); ?>">
                    <?php if ( $poster ) : ?>
                        <?php echo xn_img( $settings['poster'], 'video__poster', '', [ 'loading' => 'lazy' ] ); ?>
                    <?php else : ?>
                        <img class="video__poster" src="https://i.ytimg.com/vi/<?php echo esc_attr( $yt_id ); ?>/maxresdefault.jpg" data-fallback="https://i.ytimg.com/vi/<?php echo esc_attr( $yt_id ); ?>/hqdefault.jpg" alt="" loading="lazy" />
                    <?php endif; ?>
                    <span class="video__play"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg></span>
                </button>
            </div>
        <?php elseif ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) : ?>
            <div class="video__frame video__frame--empty">Vlož YouTube odkaz v sekcii Video.</div>
        <?php endif; ?>

        <?php if ( xn_on( $settings, 'btn1_show' ) ) : ?>
            <div class="xn-cta-row video__cta"><?php $this->xn_render_button( $settings, 'btn1', xn_tickets_url() ); ?></div>
        <?php endif; ?>
    </div>
</section>
