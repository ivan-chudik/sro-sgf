<?php
// $settings dostupný z render() scope
$items = array_filter( $settings['items'] ?? [], function( $i ) { return xn_filled( $i, 'question' ); } );
?>
<section id="<?php echo esc_attr( $settings['anchor_id'] ?? 'faq' ); ?>" class="custom-faq xn-block xn-section xn-light" data-widget="faq">
    <div class="xn-wrap">
        <?php $this->xn_render_intro( $settings ); ?>

        <?php if ( $items ) : ?>
            <div class="faq__list">
                <?php foreach ( $items as $item ) :
                    $answer = xn_wysiwyg( $item['answer'] ?? '' ); ?>
                    <details class="faq__item"<?php echo xn_on( $item, 'open' ) ? ' open' : ''; ?>>
                        <summary><?php echo esc_html( $item['question'] ); ?></summary>
                        <?php if ( $answer !== '' ) : ?>
                            <div class="faq__answer"><?php echo $answer; ?></div>
                        <?php endif; ?>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
