<?php
// Očakávané premenné: $btn['text'], $btn['url'], $btn['style'], $btn['size']
// Voliteľné: $btn['prefix'] (default 'xn'), $btn['fallback'] (URL keď je url prázdna),
//            $btn['class'], $btn['attrs'] (['data-x' => 'y']), $btn['html'] (už escapovaný obsah namiesto text)
$btn = wp_parse_args($btn ?? [], [
    'text'     => '',
    'html'     => '',
    'url'      => '',
    'fallback' => '',
    'style'    => 'primary',
    'size'     => 'md',
    'prefix'   => 'xn',
    'class'    => '',
    'attrs'    => [],
]);
if ( $btn['text'] === '' && $btn['html'] === '' ) return;

$btn_classes = [
    $btn['prefix'] . '-btn',
    $btn['prefix'] . '-btn--' . $btn['style'],
    $btn['prefix'] . '-btn--' . $btn['size'],
    $btn['class'],
];
?>
<a class="<?php echo esc_attr( trim( implode( ' ', $btn_classes ) ) ); ?>"<?php
    echo xn_link_attrs( $btn['url'], $btn['fallback'] ?: '#' );
    foreach ( $btn['attrs'] as $k => $v ) echo ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
?>><?php echo $btn['html'] !== '' ? $btn['html'] : esc_html( $btn['text'] ); ?></a>
