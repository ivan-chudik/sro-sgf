<?php
// $settings dostupný z render() scope
$items = array_filter( $settings['items'] ?? [], function( $i ) { return xn_filled( $i, 'text' ); } );
if ( ! $items ) return;
?>
<div class="custom-ticker xn-block" data-widget="ticker" aria-hidden="true">
    <div class="ticker__track">
        <?php for ( $round = 0; $round < 4; $round++ ) : ?>
            <?php foreach ( $items as $item ) : ?>
                <span class="ticker__item<?php echo xn_on( $item, 'grad' ) ? ' xn-grad' : ''; ?>"><?php echo esc_html( $item['text'] ); ?></span>
            <?php endforeach; ?>
        <?php endfor; ?>
    </div>
</div>
