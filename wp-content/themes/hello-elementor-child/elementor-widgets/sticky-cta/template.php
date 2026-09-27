<?php
// $settings dostupný z render() scope
?>
<div class="custom-sticky-cta xn-block" data-widget="sticky-cta">
    <?php $this->xn_render_button( $settings, 'btn1', xn_tickets_url() ); ?>
    <?php $this->xn_render_button( $settings, 'btn2', '#livestream' ); ?>
</div>
