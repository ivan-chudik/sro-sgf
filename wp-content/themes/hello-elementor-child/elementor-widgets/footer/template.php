<?php
// $settings dostupný z render() scope
$icons = [
    'fb' => [ 'Facebook',  '<path d="M13.5 22v-8.2h2.8l.4-3.3h-3.2V8.4c0-.9.3-1.6 1.6-1.6h1.7V3.9c-.3 0-1.3-.1-2.5-.1-2.5 0-4.2 1.5-4.2 4.3v2.4H7.3v3.3h2.8V22h3.4z"/>' ],
    'ig' => [ 'Instagram', '<path d="M12 7.3a4.7 4.7 0 1 0 0 9.4 4.7 4.7 0 0 0 0-9.4zm0 7.7a3 3 0 1 1 0-6 3 3 0 0 1 0 6zm5.9-7.9a1.1 1.1 0 1 1-2.2 0 1.1 1.1 0 0 1 2.2 0zM12 2.2c-2.7 0-3 0-4 .1-1.1 0-1.8.2-2.4.5-.7.2-1.2.6-1.8 1.1-.5.6-.9 1.1-1.1 1.8-.3.6-.5 1.3-.5 2.4-.1 1-.1 1.3-.1 4s0 3 .1 4c0 1.1.2 1.8.5 2.4.2.7.6 1.2 1.1 1.8.6.5 1.1.9 1.8 1.1.6.3 1.3.5 2.4.5 1 .1 1.3.1 4 .1s3 0 4-.1c1.1 0 1.8-.2 2.4-.5.7-.2 1.2-.6 1.8-1.1.5-.6.9-1.1 1.1-1.8.3-.6.5-1.3.5-2.4.1-1 .1-1.3.1-4s0-3-.1-4c0-1.1-.2-1.8-.5-2.4-.2-.7-.6-1.2-1.1-1.8-.6-.5-1.1-.9-1.8-1.1-.6-.3-1.3-.5-2.4-.5-1-.1-1.3-.1-4-.1zm0 1.8c2.7 0 3 0 4 .1 1 0 1.5.2 1.8.3.5.2.8.4 1.1.7.3.3.6.7.7 1.1.1.3.3.9.3 1.8.1 1 .1 1.3.1 4s0 3-.1 4c0 1-.2 1.5-.3 1.8-.2.5-.4.8-.7 1.1-.3.3-.7.6-1.1.7-.3.1-.9.3-1.8.3-1 .1-1.3.1-4 .1s-3 0-4-.1c-1 0-1.5-.2-1.8-.3-.5-.2-.8-.4-1.1-.7-.3-.3-.6-.7-.7-1.1-.1-.3-.3-.9-.3-1.8-.1-1-.1-1.3-.1-4s0-3 .1-4c0-1 .2-1.5.3-1.8.2-.5.4-.8.7-1.1.3-.3.7-.6 1.1-.7.3-.1.9-.3 1.8-.3 1-.1 1.3-.1 4-.1z"/>' ],
    'yt' => [ 'YouTube',   '<path d="M23 7.2s-.2-1.6-.9-2.3c-.9-.9-1.9-.9-2.3-1C16.5 3.7 12 3.7 12 3.7s-4.5 0-7.8.2c-.5.1-1.5.1-2.3 1C1.2 5.6 1 7.2 1 7.2S.8 9.1.8 11v1.8c0 1.9.2 3.8.2 3.8s.2 1.6.9 2.3c.9.9 2 .9 2.5 1 1.8.2 7.6.2 7.6.2s4.5 0 7.8-.2c.5-.1 1.5-.1 2.3-1 .7-.7.9-2.3.9-2.3s.2-1.9.2-3.8V11c0-1.9-.2-3.8-.2-3.8zM9.7 15V8.4l6.1 3.3L9.7 15z"/>' ],
];
?>
<footer class="custom-footer xn-block" data-widget="footer">
    <div class="xn-wrap">
        <div class="footer__grid">
            <?php foreach ( $settings['columns'] ?? [] as $col ) :
                $content = xn_wysiwyg( $col['content'] ?? '' ); ?>
                <div class="footer__col">
                    <?php if ( xn_filled( $col, 'title' ) ) : ?>
                        <h4><?php echo esc_html( $col['title'] ); ?></h4>
                    <?php endif; ?>
                    <?php if ( $content !== '' ) : ?>
                        <div class="footer__text"><?php echo $content; ?></div>
                    <?php endif; ?>
                    <?php if ( xn_on( $col, 'social' ) ) : ?>
                        <div class="footer__soc" aria-label="<?php echo esc_attr( $settings['social_label'] ?? '' ); ?>">
                            <?php foreach ( $icons as $n => $icon ) :
                                if ( empty( $settings[ $n . '_url' ]['url'] ) ) continue; ?>
                                <a<?php echo xn_link_attrs( $settings[ $n . '_url' ] ); ?> aria-label="<?php echo esc_attr( $settings[ $n . '_label' ] ?? $icon[0] ); ?>" title="<?php echo esc_attr( $icon[0] ); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $icon[1]; ?></svg></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ( xn_on( $settings, 'bottom_show' ) ) : ?>
            <div class="footer__bottom">
                <span><?php echo esc_html( $settings['bottom_left'] ?? '' ); ?></span>
                <span><?php echo esc_html( $settings['bottom_right'] ?? '' ); ?></span>
            </div>
        <?php endif; ?>
    </div>
</footer>
