<?php
// $settings dostupný z render() scope
$fallback = ( $settings['tickets_url']['url'] ?? '' ) ?: xn_tickets_url();
$note     = xn_on( $settings, 'note_show' ) ? xn_wysiwyg( $settings['note_text'] ?? '' ) : '';
?>
<section id="<?php echo esc_attr( $settings['anchor_id'] ?? 'harmonogram' ); ?>" class="custom-schedule xn-block xn-section xn-light" data-widget="schedule">
    <div class="xn-wrap">
        <?php $this->xn_render_intro( $settings ); ?>

        <div class="schedule__grid">
            <?php foreach ( $settings['days'] ?? [] as $d ) :
                $url = ( $d['btn_url']['url'] ?? '' ) ? $d['btn_url'] : $fallback; ?>
                <article class="schedule__day elementor-repeater-item-<?php echo esc_attr( $d['_id'] ?? '' ); ?>">
                    <?php if ( xn_filled( $d, 'label' ) ) : ?>
                        <div class="schedule__label"><?php echo esc_html( $d['label'] ); ?></div>
                    <?php endif; ?>
                    <?php if ( xn_filled( $d, 'title' ) ) : ?>
                        <h3 class="schedule__title xn-disp"><?php echo esc_html( $d['title'] ); ?></h3>
                    <?php endif; ?>
                    <?php if ( xn_filled( $d, 'date' ) ) : ?>
                        <div class="schedule__date"><?php echo esc_html( $d['date'] ); ?></div>
                    <?php endif; ?>

                    <?php $rows = xn_lines( $d['items'] ?? '' ); if ( $rows ) : ?>
                        <ul class="schedule__list">
                            <?php foreach ( $rows as $row ) :
                                list( $tag, $text, $sub ) = xn_cols( $row, 3 );
                                $variant = xn_tag_variant( $tag, $settings['tags_grad'] ?? '', $settings['tags_orchid'] ?? '' ); ?>
                                <li>
                                    <span class="schedule__tag schedule__tag--<?php echo esc_attr( $variant ); ?>"><?php echo esc_html( $tag ); ?></span>
                                    <div><?php echo esc_html( $text ); ?><?php if ( $sub !== '' ) : ?><em><?php echo esc_html( $sub ); ?></em><?php endif; ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php xn_button( [
                        'text'  => $d['btn_text'] ?? '',
                        'url'   => $url,
                        'style' => $d['btn_style'] ?? 'grad',
                        'class' => 'schedule__btn',
                    ] ); ?>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ( xn_on( $settings, 'note_show' ) ) : ?>
            <div class="schedule__note">
                <?php if ( xn_filled( $settings, 'note_label' ) ) : ?>
                    <span class="schedule__status"><?php echo esc_html( $settings['note_label'] ); ?></span>
                <?php endif; ?>
                <?php if ( $note !== '' ) : ?>
                    <div class="schedule__note-text"><?php echo $note; ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
