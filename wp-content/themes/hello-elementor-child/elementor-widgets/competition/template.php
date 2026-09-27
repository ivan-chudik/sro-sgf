<?php
// $settings dostupný z render() scope
$box_head = function( $b ) use ( $settings ) {
    if ( xn_filled( $settings, $b . '_k' ) )     echo '<div class="competition__k">' . esc_html( $settings[ $b . '_k' ] ) . '</div>';
    if ( xn_filled( $settings, $b . '_title' ) ) echo '<h3 class="competition__title xn-disp">' . esc_html( $settings[ $b . '_title' ] ) . '</h3>';
    $text = xn_wysiwyg( $settings[ $b . '_text' ] ?? '' );
    if ( $text !== '' ) echo '<div class="competition__text">' . $text . '</div>';
};
$box_note = function( $b ) use ( $settings ) {
    $note = xn_wysiwyg( $settings[ $b . '_note' ] ?? '' );
    if ( $note !== '' ) echo '<div class="competition__note">' . $note . '</div>';
};
?>
<section id="<?php echo esc_attr( $settings['anchor_id'] ?? 'sutaz' ); ?>" class="custom-competition xn-block xn-section" data-widget="competition">
    <?php if ( xn_on( $settings, 'ribbons_show' ) ) : ?>
        <img class="xn-rib competition__rib" src="<?php echo esc_url( xn_asset( 'img/xn-ribbons.png' ) ); ?>" alt="" />
    <?php endif; ?>

    <div class="xn-wrap xn-z">
        <?php $this->xn_render_intro( $settings ); ?>

        <div class="competition__grid">

            <?php if ( xn_on( $settings, 'box1_show' ) ) : ?>
                <article class="competition__box">
                    <?php $box_head( 'box1' ); ?>
                    <?php $rows = xn_lines( $settings['box1_list'] ?? '' ); if ( $rows ) : ?>
                        <ul class="competition__list">
                            <?php foreach ( $rows as $row ) :
                                list( $tag, $name, $sub ) = xn_cols( $row, 3 );
                                $variant = xn_tag_variant( $tag, $settings['tags_grad'] ?? '', $settings['tags_orchid'] ?? '' ); ?>
                                <li>
                                    <b class="competition__tag competition__tag--<?php echo esc_attr( $variant ); ?>"><?php echo esc_html( $tag ); ?></b>
                                    <div><span><?php echo esc_html( $name ); ?></span><?php if ( $sub !== '' ) : ?><small><?php echo esc_html( $sub ); ?></small><?php endif; ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </article>
            <?php endif; ?>

            <?php if ( xn_on( $settings, 'box2_show' ) ) : ?>
                <article class="competition__box">
                    <?php $box_head( 'box2' ); ?>
                    <?php
                    $chips_grad = xn_lines( $settings['box2_chips_grad'] ?? '' );
                    $chips      = xn_lines( $settings['box2_chips'] ?? '' );
                    if ( $chips_grad || $chips ) : ?>
                        <div class="competition__chips">
                            <?php foreach ( $chips_grad as $chip ) : ?><span class="competition__chip competition__chip--grad"><?php echo esc_html( $chip ); ?></span><?php endforeach; ?>
                            <?php foreach ( $chips as $chip ) : ?><span class="competition__chip"><?php echo esc_html( $chip ); ?></span><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php $box_note( 'box2' ); ?>
                </article>
            <?php endif; ?>

            <?php if ( xn_on( $settings, 'box3_show' ) ) : ?>
                <article class="competition__box">
                    <?php $box_head( 'box3' ); ?>
                    <?php if ( ! empty( $settings['box3_docs'] ) ) : ?>
                        <ul class="competition__docs">
                            <?php foreach ( $settings['box3_docs'] as $doc ) :
                                $ready = ! empty( $doc['url']['url'] ); ?>
                                <li class="<?php echo $ready ? 'is-ready' : ''; ?>">
                                    <i></i>
                                    <?php if ( $ready ) : ?>
                                        <a<?php echo xn_link_attrs( $doc['url'], '', (bool) preg_match( '/\.pdf($|\?)/i', $doc['url']['url'] ) ); ?>><?php echo esc_html( $doc['title'] ?? '' ); ?></a>
                                    <?php else : ?>
                                        <span><?php echo esc_html( $doc['title'] ?? '' ); ?></span>
                                    <?php endif; ?>
                                    <?php if ( xn_filled( $doc, 'meta' ) ) : ?>
                                        <em><?php echo esc_html( $doc['meta'] ); ?></em>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php $box_note( 'box3' ); ?>
                </article>
            <?php endif; ?>

        </div>
    </div>
</section>
