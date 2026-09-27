<?php
// Očakávané premenné: $btn['text'], $btn['url'], $btn['target'], $btn['style'], $btn['size']
$btn = wp_parse_args($btn ?? [], [
    'text'   => '',
    'url'    => '#',
    'target' => '_self',
    'style'  => 'primary',
    'size'   => 'md',
]);
if ( empty($btn['text']) ) return;
?>
<a href="<?php echo esc_url($btn['url']); ?>"
   target="<?php echo esc_attr($btn['target']); ?>"
   <?php if ($btn['target'] === '_blank') echo 'rel="noopener noreferrer"'; ?>
   class="{widget-prefix}-btn {widget-prefix}-btn--<?php echo esc_attr($btn['style']); ?> {widget-prefix}-btn--<?php echo esc_attr($btn['size']); ?>">
    <?php echo esc_html($btn['text']); ?>
</a>
