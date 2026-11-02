<?php
// $settings dostupný z render() scope
$groups = [];
foreach ( [ 1 => '', 2 => '2' ] as $n => $sfx ) {
    if ( ! xn_on( $settings, 'group' . $n . '_show' ) ) continue;
    $logos = $settings[ 'logos' . $sfx ] ?? [];
    if ( ! $logos ) continue;
    $groups[] = [
        'label' => xn_on( $settings, 'label' . $sfx . '_show' ) ? ( $settings[ 'label' . $sfx ] ?? '' ) : '',
        'logos' => $logos,
    ];
}
if ( ! $groups ) return;
?>
<section class="custom-partners xn-block xn-section" data-widget="partners">
    <div class="xn-wrap">
        <div class="partners__groups<?php echo xn_on( $settings, 'divider_show' ) ? ' has-divider' : ''; ?>">
            <?php foreach ( $groups as $group ) : ?>
                <div class="partners__group">
                    <?php if ( trim( $group['label'] ) !== '' ) : ?>
                        <div class="partners__label"><?php echo esc_html( $group['label'] ); ?></div>
                    <?php endif; ?>
                    <div class="partners__row">
                        <?php foreach ( $group['logos'] as $logo ) :
                            $img   = xn_img( $logo['image'] ?? [], '', $logo['alt'] ?? '', [ 'loading' => 'lazy' ] );
                            $link  = xn_link_attrs( $logo['link'] ?? '' );
                            $class = 'partners__item elementor-repeater-item-' . ( $logo['_id'] ?? '' );
                            if ( ! $img ) continue; ?>
                            <?php if ( $link ) : ?>
                                <a class="<?php echo esc_attr( $class ); ?>"<?php echo $link; ?>><?php echo $img; ?></a>
                            <?php else : ?>
                                <span class="<?php echo esc_attr( $class ); ?>"><?php echo $img; ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
