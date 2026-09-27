<?php
// $settings dostupný z render() scope
$items = $settings['items'] ?? [];
if ( ! $items ) return;
?>
<section class="custom-facts xn-block xn-section" data-widget="facts">
    <div class="xn-wrap">
        <div class="facts__grid">
            <?php foreach ( $items as $item ) :
                $text = xn_wysiwyg( $item['text'] ?? '' ); ?>
                <div class="facts__item elementor-repeater-item-<?php echo esc_attr( $item['_id'] ?? '' ); ?>">
                    <?php if ( xn_filled( $item, 'label' ) ) : ?>
                        <div class="facts__label"><?php echo esc_html( $item['label'] ); ?></div>
                    <?php endif; ?>
                    <div class="facts__value xn-grad"><?php echo esc_html( $item['value'] ?? '' ); ?><?php if ( xn_filled( $item, 'suffix' ) ) : ?><small><?php echo esc_html( $item['suffix'] ); ?></small><?php endif; ?></div>
                    <?php if ( $text !== '' ) : ?>
                        <div class="facts__text"><?php echo $text; ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
