<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap ticket-designer-wrap">
    <h1><?php _e('Ticket Designer', 'alttag-registrations'); ?></h1>

    <?php if (isset($_GET['message']) && $_GET['message'] === 'saved'): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Settings saved successfully.', 'alttag-registrations'); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($available_products)): ?>
    <div class="ticket-designer-product-switcher" style="margin-bottom: 20px; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <label for="ticket-product-select" style="font-weight: 600; white-space: nowrap;">
            <?php _e('Design for:', 'alttag-registrations'); ?>
        </label>
        <select id="ticket-product-select" onchange="window.location.href = this.value;" style="min-width: 300px; max-width: 100%;">
            <?php
            $global_url = add_query_arg([
                'post_type' => 'participant',
                'page' => 'ticket-designer',
                'ticket_product_id' => 0,
                'ticket_lang' => $current_language ?? '',
            ], admin_url('edit.php'));
            ?>
            <option value="<?php echo esc_attr($global_url); ?>" <?php selected($current_product_id, 0); ?>>
                <?php _e('Global (default)', 'alttag-registrations'); ?>
            </option>
            <?php foreach ($available_products as $prod_id => $prod_name): ?>
                <?php
                $product_edit_language = $this->getProductLanguage($prod_id);
                $product_url = add_query_arg([
                    'post_type' => 'participant',
                    'page' => 'ticket-designer',
                    'ticket_product_id' => $prod_id,
                    'ticket_lang' => $product_edit_language ?: ($current_language ?? ''),
                ], admin_url('edit.php'));
                ?>
                <option value="<?php echo esc_attr($product_url); ?>" <?php selected($current_product_id, $prod_id); ?>>
                    <?php echo esc_html($prod_name); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if ($is_multilingual && !empty($available_languages)): ?>
    <div class="ticket-designer-language-switcher" style="margin-bottom: 20px; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
        <label for="ticket-language-select" style="font-weight: 600; margin-right: 10px;">
            <?php _e('Edit ticket for language:', 'alttag-registrations'); ?>
        </label>
        <select id="ticket-language-select" onchange="window.location.href = this.value;">
            <?php foreach ($available_languages as $lang_code => $lang_name): ?>
                <?php
                $lang_url_args = [
                    'post_type' => 'participant',
                    'page' => 'ticket-designer',
                    'ticket_lang' => $lang_code,
                ];
                if ($current_product_id > 0) {
                    $lang_url_args['ticket_product_id'] = $current_product_id;
                }
                $lang_url = add_query_arg($lang_url_args, admin_url('edit.php'));
                ?>
                <option value="<?php echo esc_attr($lang_url); ?>" <?php selected($current_language, $lang_code); ?>>
                    <?php echo esc_html($lang_name); ?> (<?php echo strtoupper($lang_code); ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <span style="margin-left: 15px; color: #666;">
            <?php
            /* translators: %s: language name */
            printf(__('Currently editing: %s', 'alttag-registrations'), '<strong>' . esc_html($available_languages[$current_language] ?? $current_language) . '</strong>');
            ?>
        </span>
    </div>
    <?php endif; ?>

    <div class="ticket-designer-container">
        <!-- Settings Panel -->
        <div class="ticket-designer-settings">
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="ticket-designer-form">
                <input type="hidden" name="action" value="save_ticket_design_settings">
                <input type="hidden" name="ticket_lang" value="<?php echo esc_attr($current_language ?? ''); ?>">
                <input type="hidden" name="ticket_product_id" value="<?php echo esc_attr($current_product_id); ?>">
                <?php wp_nonce_field('save_ticket_design_settings'); ?>

                <?php if ($current_product_id > 0): ?>
                <div class="product-design-banner">
                    <div class="banner-row">
                        <label class="tdx-toggle">
                            <input type="checkbox" name="ticket_design_enabled" value="1"
                                   <?php checked($is_product_design_enabled, true); ?>>
                            <?php _e('Use custom ticket design for this product', 'alttag-registrations'); ?>
                        </label>
                        <button type="button" id="copy-from-global" class="button button-secondary">
                            <span class="dashicons dashicons-admin-page"></span>
                            <?php _e('Copy from Global', 'alttag-registrations'); ?>
                        </button>
                    </div>
                    <p class="description">
                        <?php _e('When disabled, the global ticket design will be used.', 'alttag-registrations'); ?>
                    </p>
                </div>
                <?php endif; ?>

                <sl-tab-group placement="top">
                    <sl-tab slot="nav" panel="header"><?php _e('Header', 'alttag-registrations'); ?></sl-tab>
                    <sl-tab slot="nav" panel="body"><?php _e('Body', 'alttag-registrations'); ?></sl-tab>
                    <sl-tab slot="nav" panel="footer"><?php _e('Footer', 'alttag-registrations'); ?></sl-tab>

                    <sl-tab-panel name="header">

                <!-- Header Logo -->
                <div class="settings-section">
                    <h2>
                        <span><?php _e('Header Logo', 'alttag-registrations'); ?></span>
                        <button type="button" class="button section-reset ms-auto"
                                data-section="header_logo"
                                title="<?php esc_attr_e('Reset this section to defaults', 'alttag-registrations'); ?>">
                            <span class="dashicons dashicons-image-rotate"></span>
                            <?php _e('Reset', 'alttag-registrations'); ?>
                        </button>
                    </h2>
                    <p class="description">
                        <?php _e('Small logo image rendered above the ticket frame.', 'alttag-registrations'); ?>
                    </p>
                    <div class="form-group">
                        <label><?php _e('Logo Image', 'alttag-registrations'); ?></label>
                        <div class="image-picker-row">
                            <button type="button"
                                    class="logo-preview-btn image-preview-btn upload-media-button"
                                    data-target="header_image"
                                    title="<?php esc_attr_e('Click to change logo', 'alttag-registrations'); ?>">
                                <?php if (!empty($settings['header_image'])): ?>
                                    <img class="logo-preview-img" src="<?php echo esc_url($settings['header_image']); ?>" alt="">
                                <?php else: ?>
                                    <span class="logo-preview-empty dashicons dashicons-format-image"></span>
                                <?php endif; ?>
                            </button>
                            <div class="logo-url-field">
                                <input type="text"
                                       name="header_image"
                                       id="header_image"
                                       value="<?php echo esc_attr($settings['header_image']); ?>"
                                       class="content-row-image-url settings-input"
                                       placeholder="<?php esc_attr_e('Logo URL — click the tile to browse', 'alttag-registrations'); ?>">
                            </div>
                        </div>
                    </div>
                    <?php $tf_for_logo = isset($settings['ticket_frame']) && is_array($settings['ticket_frame']) ? $settings['ticket_frame'] : []; ?>
                    <div class="form-group">
                        <label for="header_logo_height"
                               title="<?php esc_attr_e('Vertical space reserved above the ticket where the logo image is placed and centered.', 'alttag-registrations'); ?>">
                            <?php _e('Header height (px)', 'alttag-registrations'); ?>
                        </label>
                        <input type="number" name="ticket_frame[y]" id="header_logo_height"
                               value="<?php echo esc_attr($tf_for_logo['y'] ?? 150); ?>"
                               class="settings-input" step="1" min="0">
                    </div>
                </div>

                <!-- Ticket Frame — Bootstrap 5 layout -->
                <?php $tf = isset($settings['ticket_frame']) && is_array($settings['ticket_frame']) ? $settings['ticket_frame'] : []; ?>
                <div class="settings-section" id="ticket-frame-section">
                    <h2>
                        <span><?php _e('Ticket Frame', 'alttag-registrations'); ?></span>
                        <button type="button" class="button section-reset"
                                data-section="ticket_frame"
                                title="<?php esc_attr_e('Reset this section to defaults', 'alttag-registrations'); ?>">
                            <span class="dashicons dashicons-image-rotate"></span>
                            <?php _e('Reset', 'alttag-registrations'); ?>
                        </button>
                        <div class="form-check form-switch ms-auto mb-0">
                            <input class="form-check-input settings-input" type="checkbox"
                                   role="switch"
                                   name="ticket_frame[enabled]"
                                   id="ticket_frame_enabled"
                                   value="1"
                                   <?php checked(!empty($tf['enabled'])); ?>>
                            <label class="form-check-label" for="ticket_frame_enabled"><?php _e('Enable', 'alttag-registrations'); ?></label>
                        </div>
                    </h2>
                    <p class="description">
                        <?php _e('Draws a styled ticket body (rounded rectangle with background, left stub, perforation and vertical right text). When enabled, the Header Image is used as a small logo above the frame — not as full background.', 'alttag-registrations'); ?>
                    </p>

                    <div class="ticket-frame-body">

                        <!-- 1. LAYOUT -->
                        <div class="card mb-3">
                            <div class="card-body">
                                <h6 class="card-title"><?php _e('1. Layout', 'alttag-registrations'); ?></h6>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label"
                                               title="<?php esc_attr_e('Minimum height — ticket auto-grows if the content is taller.', 'alttag-registrations'); ?>">
                                            <?php _e('Min height (px)', 'alttag-registrations'); ?>
                                        </label>
                                        <input type="number" name="ticket_frame[height]"
                                               value="<?php echo esc_attr($tf['height'] ?? 438); ?>"
                                               class="form-control form-control-sm settings-input" step="1">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label"><?php _e('Odsadenie od loga (px)', 'alttag-registrations'); ?></label>
                                        <input type="number" name="ticket_frame[logo_gap]"
                                               value="<?php echo esc_attr($tf['logo_gap'] ?? 0); ?>"
                                               class="form-control form-control-sm settings-input" step="1" min="0">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label"><?php _e('Border radius (px)', 'alttag-registrations'); ?></label>
                                        <input type="number" name="ticket_frame[border_radius]"
                                               value="<?php echo esc_attr($tf['border_radius'] ?? 26); ?>"
                                               class="form-control form-control-sm settings-input" step="1" min="0">
                                    </div>
                                </div>
                                <!-- Top Y offset lives in Header Logo section as "Header height" -->
                            </div>
                        </div>

                        <!-- 2. BACKGROUND -->
                        <div class="card mb-3">
                            <div class="card-body">
                                <h6 class="card-title"><?php _e('2. Background', 'alttag-registrations'); ?></h6>
                                <div class="mb-3">
                                    <label class="form-label"><?php _e('Type', 'alttag-registrations'); ?></label>
                                    <select name="ticket_frame[bg_type]" class="form-select form-select-sm ticket-frame-bg-type settings-input">
                                        <option value="solid" <?php selected($tf['bg_type'] ?? 'gradient', 'solid'); ?>><?php _e('Solid color', 'alttag-registrations'); ?></option>
                                        <option value="gradient" <?php selected($tf['bg_type'] ?? 'gradient', 'gradient'); ?>><?php _e('Gradient (two colors)', 'alttag-registrations'); ?></option>
                                        <option value="image" <?php selected($tf['bg_type'] ?? 'gradient', 'image'); ?>><?php _e('Image', 'alttag-registrations'); ?></option>
                                    </select>
                                </div>
                                <div class="row g-3 ticket-frame-bg-color-row">
                                    <div class="col-md-4">
                                        <label class="form-label tf-bg-label-solid" style="display:none;"><?php _e('Background color', 'alttag-registrations'); ?></label>
                                        <label class="form-label tf-bg-label-gradient"><?php _e('Gradient start color', 'alttag-registrations'); ?></label>
                                        <input type="text" name="ticket_frame[bg_color]"
                                               value="<?php echo esc_attr($tf['bg_color'] ?? '#C479E6'); ?>"
                                               class="color-picker settings-input">
                                    </div>
                                    <div class="col-md-4 ticket-frame-bg-color-2">
                                        <label class="form-label"><?php _e('Gradient end color', 'alttag-registrations'); ?></label>
                                        <input type="text" name="ticket_frame[bg_color_2]"
                                               value="<?php echo esc_attr($tf['bg_color_2'] ?? '#54C8EA'); ?>"
                                               class="color-picker settings-input">
                                    </div>
                                    <div class="col-md-4 ticket-frame-gradient-direction">
                                        <label class="form-label"><?php _e('Direction', 'alttag-registrations'); ?></label>
                                        <select name="ticket_frame[gradient_direction]" class="form-select form-select-sm settings-input">
                                            <option value="horizontal" <?php selected($tf['gradient_direction'] ?? 'horizontal', 'horizontal'); ?>><?php _e('Left → Right', 'alttag-registrations'); ?></option>
                                            <option value="vertical" <?php selected($tf['gradient_direction'] ?? 'horizontal', 'vertical'); ?>><?php _e('Top → Bottom', 'alttag-registrations'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                <div class="mt-3 ticket-frame-bg-image-row" style="display:none;">
                                    <label class="form-label"><?php _e('Background image', 'alttag-registrations'); ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" name="ticket_frame[bg_image]"
                                               id="ticket_frame_bg_image"
                                               value="<?php echo esc_attr($tf['bg_image'] ?? ''); ?>"
                                               class="form-control settings-input">
                                        <button type="button" class="btn btn-outline-secondary upload-media-button" data-target="ticket_frame_bg_image">
                                            <?php _e('Select Image', 'alttag-registrations'); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 3. LEFT STUB -->
                        <div class="card mb-3">
                            <div class="card-body">
                                <h6 class="card-title"><?php _e('3. Left stub', 'alttag-registrations'); ?></h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label"><?php _e('Stub color', 'alttag-registrations'); ?></label>
                                        <input type="text" name="ticket_frame[stub_color]"
                                               value="<?php echo esc_attr($tf['stub_color'] ?? '#9C1C90'); ?>"
                                               class="color-picker settings-input">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label"><?php _e('Stub width (px)', 'alttag-registrations'); ?></label>
                                        <input type="number" name="ticket_frame[stub_width]"
                                               value="<?php echo esc_attr($tf['stub_width'] ?? 107); ?>"
                                               class="form-control form-control-sm settings-input" step="1" min="0">
                                    </div>
                                </div>
                                <div class="form-text mt-2">
                                    <?php _e('A stylized ticket icon is drawn centered in the stub automatically.', 'alttag-registrations'); ?>
                                </div>
                            </div>
                        </div>

                        <!-- 4. PERFORATION -->
                        <div class="card mb-3">
                            <div class="card-body">
                                <h6 class="card-title"><?php _e('4. Perforation (dashed line + white notches)', 'alttag-registrations'); ?></h6>
                                <input type="hidden" name="ticket_frame[perf_enabled]" value="1">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label"><?php _e('X position (px from ticket left)', 'alttag-registrations'); ?></label>
                                        <input type="number" name="ticket_frame[perf_x]"
                                               value="<?php echo esc_attr($tf['perf_x'] ?? 1013); ?>"
                                               class="form-control form-control-sm settings-input" step="1" min="0">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label"><?php _e('Line color', 'alttag-registrations'); ?></label>
                                        <input type="text" name="ticket_frame[perf_color]"
                                               value="<?php echo esc_attr($tf['perf_color'] ?? '#0D1A26'); ?>"
                                               class="color-picker settings-input">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 5. CUT LINE -->
                        <div class="card mb-3">
                            <div class="card-body">
                                <h6 class="card-title"><?php _e('5. Cut line below ticket (scissors + dashed line)', 'alttag-registrations'); ?></h6>
                                <input type="hidden" name="ticket_frame[cut_line_enabled]" value="1">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label"><?php _e('Line color', 'alttag-registrations'); ?></label>
                                        <input type="text" name="ticket_frame[cut_line_color]"
                                               value="<?php echo esc_attr($tf['cut_line_color'] ?? '#111111'); ?>"
                                               class="color-picker settings-input">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label"><?php _e('Gap from ticket bottom (px)', 'alttag-registrations'); ?></label>
                                        <input type="number" name="ticket_frame[cut_line_gap]"
                                               value="<?php echo esc_attr($tf['cut_line_gap'] ?? 60); ?>"
                                               class="form-control form-control-sm settings-input" step="1" min="0">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 6. RIGHT VERTICAL TEXT -->
                        <div class="card mb-3">
                            <div class="card-body">
                                <h6 class="card-title"><?php _e('6. Right vertical text', 'alttag-registrations'); ?></h6>
                                <div class="mb-3">
                                    <label class="form-label"><?php _e('Text (rotated 90°, read bottom → top on the right edge). Leave empty to hide.', 'alttag-registrations'); ?></label>
                                    <textarea name="ticket_frame[right_text]" rows="2"
                                              class="form-control form-control-sm settings-input"
                                              placeholder="<?php esc_attr_e('e.g. To validate your ticket, please scan the QR code at the entrance control.', 'alttag-registrations'); ?>"><?php echo esc_textarea($tf['right_text'] ?? "To validate your ticket, please scan\nthe QR code at the entrance control."); ?></textarea>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label"><?php _e('Text color', 'alttag-registrations'); ?></label>
                                        <input type="text" name="ticket_frame[right_text_color]"
                                               value="<?php echo esc_attr($tf['right_text_color'] ?? '#FFFFFF'); ?>"
                                               class="color-picker settings-input">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label"><?php _e('Font size (px)', 'alttag-registrations'); ?></label>
                                        <input type="number" name="ticket_frame[right_text_size]"
                                               value="<?php echo esc_attr($tf['right_text_size'] ?? 9); ?>"
                                               class="form-control form-control-sm settings-input" step="1">
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <script>
                    (function ($) {
                        $(function () {
                            var $section = $('#ticket-frame-section');
                            var $body    = $section.find('.ticket-frame-body');
                            var $enable  = $('#ticket_frame_enabled');
                            var $type    = $section.find('.ticket-frame-bg-type');

                            function toggleBody() {
                                $body.css('opacity', $enable.is(':checked') ? '1' : '0.4');
                                $body.find('input, select, textarea').prop('disabled', !$enable.is(':checked'));
                                // Keep the enable checkbox itself always active
                                $enable.prop('disabled', false);
                            }
                            function toggleBgType() {
                                var t = $type.val();
                                $section.find('.ticket-frame-bg-color-row').toggle(t !== 'image');
                                $section.find('.ticket-frame-bg-color-2, .ticket-frame-gradient-direction').toggle(t === 'gradient');
                                $section.find('.ticket-frame-bg-image-row').toggle(t === 'image');
                                // Solid label swap
                                if (t === 'solid') {
                                    $section.find('.tf-bg-label-solid').show();
                                    $section.find('.tf-bg-label-gradient').hide();
                                } else {
                                    $section.find('.tf-bg-label-solid').hide();
                                    $section.find('.tf-bg-label-gradient').show();
                                }
                            }

                            $enable.on('change', toggleBody);
                            $type.on('change', toggleBgType);
                            toggleBody();
                            toggleBgType();
                        });
                    })(jQuery);
                    </script>
                </div>

                <!-- Left Column -->
                <div class="settings-section">
                    <h2>
                        <span><?php _e('Left Column', 'alttag-registrations'); ?></span>
                        <button type="button" class="button section-reset ms-auto"
                                data-section="left_column"
                                title="<?php esc_attr_e('Reset this section to defaults', 'alttag-registrations'); ?>">
                            <span class="dashicons dashicons-image-rotate"></span>
                            <?php _e('Reset', 'alttag-registrations'); ?>
                        </button>
                    </h2>
                    <!-- X/Y auto-computed from ticket layout (stub width + padding) -->
                    <input type="hidden" name="left_column_x" id="left_column_x"
                           value="<?php echo esc_attr($settings['left_column']['x']); ?>">
                    <input type="hidden" name="left_column_y" id="left_column_y"
                           value="<?php echo esc_attr($settings['left_column']['y']); ?>">
                    <div class="form-group">
                        <label for="left_column_width"><?php _e('Width (px)', 'alttag-registrations'); ?></label>
                        <input type="number" name="left_column_width" id="left_column_width"
                               value="<?php echo esc_attr($settings['left_column']['width']); ?>"
                               class="small-text settings-input" step="1">
                    </div>
                    <!-- Height auto-computed from content (ticket auto-grows) -->
                    <input type="hidden" name="left_column_height" id="left_column_height"
                           value="<?php echo esc_attr($settings['left_column']['height']); ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="left_column_font_size"><?php _e('Font Size', 'alttag-registrations'); ?></label>
                            <input type="number" name="left_column_font_size" id="left_column_font_size"
                                   value="<?php echo esc_attr($settings['left_column']['font_size']); ?>"
                                   class="small-text settings-input" step="1">
                        </div>
                        <div class="form-group">
                            <label for="left_column_color"><?php _e('Text Color', 'alttag-registrations'); ?></label>
                            <input type="text" name="left_column_color" id="left_column_color"
                                   value="<?php echo esc_attr($settings['left_column']['color']); ?>"
                                   class="color-picker settings-input">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="left_column_label_color"><?php _e('Label Color', 'alttag-registrations'); ?></label>
                            <input type="text" name="left_column_label_color" id="left_column_label_color"
                                   value="<?php echo esc_attr($settings['left_column']['label_color'] ?? ''); ?>"
                                   class="color-picker settings-input"
                                   placeholder="<?php esc_attr_e('Same as text', 'alttag-registrations'); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="left_column_vertical_center"
                                   value="1"
                                   <?php checked($settings['left_column']['vertical_center'], true); ?>
                                   class="settings-input">
                            <?php _e('Vertical Center', 'alttag-registrations'); ?>
                        </label>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="left_column_label_position"><?php _e('Label Position', 'alttag-registrations'); ?></label>
                            <select name="left_column_label_position" id="left_column_label_position" class="settings-input">
                                <option value="inline" <?php selected($settings['left_column']['label_position'] ?? 'inline', 'inline'); ?>>
                                    <?php _e('Inline (Label: Value)', 'alttag-registrations'); ?>
                                </option>
                                <option value="above" <?php selected($settings['left_column']['label_position'] ?? 'inline', 'above'); ?>>
                                    <?php _e('Above value', 'alttag-registrations'); ?>
                                </option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="left_column_word_wrap"><?php _e('Word Wrap', 'alttag-registrations'); ?></label>
                            <select name="left_column_word_wrap" id="left_column_word_wrap" class="settings-input">
                                <option value="break" <?php selected($settings['left_column']['word_wrap'] ?? 'break', 'break'); ?>>
                                    <?php _e('Break long words', 'alttag-registrations'); ?>
                                </option>
                                <option value="words" <?php selected($settings['left_column']['word_wrap'] ?? 'break', 'words'); ?>>
                                    <?php _e('Keep whole words', 'alttag-registrations'); ?>
                                </option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="left_column_line_height"><?php _e('Line Height (px)', 'alttag-registrations'); ?></label>
                            <input type="number" name="left_column_line_height" id="left_column_line_height"
                                   value="<?php echo esc_attr($settings['left_column']['line_height'] ?? 35); ?>"
                                   min="1" class="small-text settings-input" step="1">
                        </div>
                        <div class="form-group"></div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="settings-section">
                    <h2>
                        <span><?php _e('Right Column', 'alttag-registrations'); ?></span>
                        <button type="button" class="button section-reset ms-auto"
                                data-section="right_column"
                                title="<?php esc_attr_e('Reset this section to defaults', 'alttag-registrations'); ?>">
                            <span class="dashicons dashicons-image-rotate"></span>
                            <?php _e('Reset', 'alttag-registrations'); ?>
                        </button>
                    </h2>
                    <!-- X/Y auto-computed from ticket layout -->
                    <input type="hidden" name="right_column_x" id="right_column_x"
                           value="<?php echo esc_attr($settings['right_column']['x']); ?>">
                    <input type="hidden" name="right_column_y" id="right_column_y"
                           value="<?php echo esc_attr($settings['right_column']['y']); ?>">
                    <div class="form-group">
                        <label for="right_column_width"><?php _e('Width (px)', 'alttag-registrations'); ?></label>
                        <input type="number" name="right_column_width" id="right_column_width"
                               value="<?php echo esc_attr($settings['right_column']['width']); ?>"
                               class="small-text settings-input" step="1">
                    </div>
                    <!-- Height auto-computed from content -->
                    <input type="hidden" name="right_column_height" id="right_column_height"
                           value="<?php echo esc_attr($settings['right_column']['height']); ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="right_column_font_size"><?php _e('Font Size', 'alttag-registrations'); ?></label>
                            <input type="number" name="right_column_font_size" id="right_column_font_size"
                                   value="<?php echo esc_attr($settings['right_column']['font_size']); ?>"
                                   class="small-text settings-input" step="1">
                        </div>
                        <div class="form-group">
                            <label for="right_column_color"><?php _e('Text Color', 'alttag-registrations'); ?></label>
                            <input type="text" name="right_column_color" id="right_column_color"
                                   value="<?php echo esc_attr($settings['right_column']['color']); ?>"
                                   class="color-picker settings-input">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="right_column_label_color"><?php _e('Label Color', 'alttag-registrations'); ?></label>
                            <input type="text" name="right_column_label_color" id="right_column_label_color"
                                   value="<?php echo esc_attr($settings['right_column']['label_color'] ?? ''); ?>"
                                   class="color-picker settings-input"
                                   placeholder="<?php esc_attr_e('Same as text', 'alttag-registrations'); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="right_column_vertical_center"
                                   value="1"
                                   <?php checked($settings['right_column']['vertical_center'], true); ?>
                                   class="settings-input">
                            <?php _e('Vertical Center', 'alttag-registrations'); ?>
                        </label>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="right_column_label_position"><?php _e('Label Position', 'alttag-registrations'); ?></label>
                            <select name="right_column_label_position" id="right_column_label_position" class="settings-input">
                                <option value="inline" <?php selected($settings['right_column']['label_position'] ?? 'inline', 'inline'); ?>>
                                    <?php _e('Inline (Label: Value)', 'alttag-registrations'); ?>
                                </option>
                                <option value="above" <?php selected($settings['right_column']['label_position'] ?? 'inline', 'above'); ?>>
                                    <?php _e('Above value', 'alttag-registrations'); ?>
                                </option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="right_column_word_wrap"><?php _e('Word Wrap', 'alttag-registrations'); ?></label>
                            <select name="right_column_word_wrap" id="right_column_word_wrap" class="settings-input">
                                <option value="break" <?php selected($settings['right_column']['word_wrap'] ?? 'break', 'break'); ?>>
                                    <?php _e('Break long words', 'alttag-registrations'); ?>
                                </option>
                                <option value="words" <?php selected($settings['right_column']['word_wrap'] ?? 'break', 'words'); ?>>
                                    <?php _e('Keep whole words', 'alttag-registrations'); ?>
                                </option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="right_column_line_height"><?php _e('Line Height (px)', 'alttag-registrations'); ?></label>
                            <input type="number" name="right_column_line_height" id="right_column_line_height"
                                   value="<?php echo esc_attr($settings['right_column']['line_height'] ?? 35); ?>"
                                   min="1" class="small-text settings-input" step="1">
                        </div>
                        <div class="form-group"></div>
                    </div>
                </div>

                <!-- QR Code -->
                <div class="settings-section">
                    <h2>
                        <span><?php _e('QR Code', 'alttag-registrations'); ?></span>
                        <button type="button" class="button section-reset ms-auto"
                                data-section="qr_code"
                                title="<?php esc_attr_e('Reset this section to defaults', 'alttag-registrations'); ?>">
                            <span class="dashicons dashicons-image-rotate"></span>
                            <?php _e('Reset', 'alttag-registrations'); ?>
                        </button>
                    </h2>
                    <p class="description">
                        <?php _e('The QR code is auto-centered in the ticket\'s right section (right of the perforation line).', 'alttag-registrations'); ?>
                    </p>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="qr_code_size"><?php _e('Size (px)', 'alttag-registrations'); ?></label>
                            <input type="number" name="qr_code_size" id="qr_code_size"
                                   value="<?php echo esc_attr($settings['qr_code']['size']); ?>"
                                   class="small-text settings-input" step="1">
                        </div>
                    </div>
                    <!-- Legacy X/Y (no longer used with frame mode) — hidden -->
                    <input type="hidden" name="qr_code_x" value="<?php echo esc_attr($settings['qr_code']['x']); ?>">
                    <input type="hidden" name="qr_code_y" value="<?php echo esc_attr($settings['qr_code']['y']); ?>">
                </div>

                <div class="settings-section">
                    <h2>
                        <span><?php _e('Ticket Fields', 'alttag-registrations'); ?></span>
                        <button type="button" id="reset-field-layout" class="button ms-auto"
                                title="<?php esc_attr_e('Redistribute fields evenly between the two columns in their natural category order', 'alttag-registrations'); ?>">
                            <span class="dashicons dashicons-image-rotate"></span>
                            <?php _e('Reset to auto', 'alttag-registrations'); ?>
                        </button>
                    </h2>
                    <p class="description">
                        <?php _e('Drag to reorder. Drag between columns to move. Uncheck to hide a field from the ticket.', 'alttag-registrations'); ?>
                    </p>
                    <div class="form-check form-switch" style="margin: 12px 0;">
                        <input class="form-check-input settings-input" type="checkbox" role="switch"
                               name="auto_field_layout"
                               id="auto_field_layout"
                               value="1"
                               <?php checked(!empty($settings['auto_field_layout'])); ?>>
                        <label class="form-check-label" for="auto_field_layout">
                            <?php _e('Auto-distribute filled fields evenly between columns', 'alttag-registrations'); ?>
                        </label>
                    </div>
                    <p class="description" style="margin-bottom: 12px;">
                        <?php _e('When enabled, empty fields (like unused company data) are skipped and the remaining populated fields are split evenly between the two columns at render time. The manual drag order below still controls the FIELD ORDER within the natural flow.', 'alttag-registrations'); ?>
                    </p>
                    <?php
                    $hidden_fields = $settings['hidden_default_fields'] ?? [];
                    $column_configs = [
                        'ticket_field_order_first' => __('First Column', 'alttag-registrations'),
                        'ticket_field_order_second' => __('Second Column', 'alttag-registrations'),
                    ];

                    // Natural column for each field, based on its source.
                    // First column = personal/contact/address, second column = company/admin/ids.
                    $default_columns = [
                        // Session virtual fields → first
                        'session_product' => 'first',
                        'session_date' => 'first',
                        'session_location' => 'first',
                        // Personal + contact + address → first
                        'first_name' => 'first', 'last_name' => 'first',
                        'email' => 'first', 'phone' => 'first',
                        'street' => 'first', 'zip' => 'first',
                        'city' => 'first', 'country' => 'first',
                        // Company + IDs + admin → second
                        'company_name' => 'second',
                        'business_id' => 'second', 'tax_id' => 'second', 'vat_id' => 'second',
                        'variable_symbol' => 'second', 'used_coupons' => 'second',
                    ];
                    // Custom FB fields → use their ticket.column setting
                    if (class_exists('\\Alttag\\Registrations\\FieldBuilder')) {
                        foreach (\Alttag\Registrations\FieldBuilder::getFields() as $fk => $fd) {
                            if (!empty($fd['is_system'])) {
                                continue;
                            }
                            if (empty($fd['ticket']['enabled'])) {
                                continue;
                            }
                            $default_columns[$fk] = ($fd['ticket']['column'] ?? 'first') === 'second' ? 'second' : 'first';
                        }
                    }

                    $first_saved = array_values(array_intersect($settings['ticket_field_order_first'] ?? [], array_keys($all_ticket_field_options)));
                    $second_saved = array_values(array_intersect($settings['ticket_field_order_second'] ?? [], array_keys($all_ticket_field_options)));
                    $assigned = array_merge($first_saved, $second_saved);
                    $unassigned = array_values(array_diff(array_keys($all_ticket_field_options), $assigned));

                    if (empty($first_saved) && empty($second_saved)) {
                        // No manual layout yet — auto-balance all fields into two
                        // columns by count. Fields are taken in their natural
                        // category order (session → personal → address → company
                        // → IDs → admin), so a mid-split keeps related fields
                        // together. Admin can still drag/reorder afterwards and
                        // the saved layout is respected on next load.
                        $all_keys = array_keys($all_ticket_field_options);
                        $split_at = (int) ceil(count($all_keys) / 2);
                        $first_saved  = array_slice($all_keys, 0, $split_at);
                        $second_saved = array_slice($all_keys, $split_at);
                    } else {
                        // Manual layout exists — send any newly-added fields to
                        // their configured default column.
                        foreach ($unassigned as $ukey) {
                            if (($default_columns[$ukey] ?? 'first') === 'second') {
                                $second_saved[] = $ukey;
                            } else {
                                $first_saved[] = $ukey;
                            }
                        }
                    }
                    $column_items = [
                        'ticket_field_order_first' => $first_saved,
                        'ticket_field_order_second' => $second_saved,
                    ];
                    ?>
                    <div class="ticket-field-columns" style="display:flex; gap:20px; flex-wrap:wrap; <?php echo !empty($settings['auto_field_layout']) ? 'display:none;' : ''; ?>">
                    <?php foreach ($column_configs as $order_key => $order_label) : ?>
                        <div style="flex:1; min-width:320px;">
                            <h3 style="margin:0 0 8px 0;"><?php echo esc_html($order_label); ?></h3>
                            <ul class="ticket-field-order-list"
                                data-field="<?php echo esc_attr($order_key); ?>"
                                style="list-style:none; margin:0; padding:0; border:1px solid #ddd; background:#fafafa; min-height:40px;">
                                <?php foreach ($column_items[$order_key] as $key) :
                                    $label = $all_ticket_field_options[$key] ?? $key;
                                    $is_hidden = in_array($key, $hidden_fields, true);
                                ?>
                                    <li data-key="<?php echo esc_attr($key); ?>"
                                        style="padding:8px 12px; border-bottom:1px solid #eee; background:#fff; cursor:move; display:flex; align-items:center; gap:8px;">
                                        <span class="dashicons dashicons-menu" style="color:#999;"></span>
                                        <label style="flex:1; cursor:pointer; display:flex; align-items:center; gap:8px; margin:0;">
                                            <input type="checkbox"
                                                   class="ticket-field-visible"
                                                   name="ticket_visible_fields[]"
                                                   value="<?php echo esc_attr($key); ?>"
                                                   <?php checked(!$is_hidden); ?>>
                                            <span><?php echo esc_html($label); ?></span>
                                        </label>
                                        <span style="color:#999; font-size:11px; font-family:monospace;">
                                            <?php echo esc_html($key); ?>
                                        </span>
                                        <input type="hidden" class="ticket-field-key-input"
                                               name="<?php echo esc_attr($order_key); ?>[]"
                                               value="<?php echo esc_attr($key); ?>">
                                        <input type="hidden" name="ticket_present_fields[]"
                                               value="<?php echo esc_attr($key); ?>">
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>

                    </sl-tab-panel>

                    <sl-tab-panel name="body">

                <!-- Content Rows Repeater -->
                <div class="settings-section">
                    <h2>
                        <span><?php _e('Content Rows', 'alttag-registrations'); ?></span>
                        <button type="button" class="button section-reset ms-auto"
                                data-section="content_rows"
                                title="<?php esc_attr_e('Remove all content rows', 'alttag-registrations'); ?>">
                            <span class="dashicons dashicons-image-rotate"></span>
                            <?php _e('Reset', 'alttag-registrations'); ?>
                        </button>
                    </h2>
                    <div id="content-rows-repeater">
                        <?php if (!empty($settings['content_rows'])): ?>
                            <?php foreach ($settings['content_rows'] as $index => $row): ?>
                                <?php include ALTTAG_REGISTRATIONS_PATH . '/templates/admin/partials/content-row-item.php'; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <button type="button" id="add-content-row" class="button">
                        <?php _e('Add Content Row', 'alttag-registrations'); ?>
                    </button>
                </div>

                    </sl-tab-panel>

                    <sl-tab-panel name="footer">

                <?php $ft = isset($settings['footer_text']) && is_array($settings['footer_text']) ? $settings['footer_text'] : []; ?>
                <div class="settings-section" id="footer-text-section">
                    <h2>
                        <span><?php _e('Footer Text', 'alttag-registrations'); ?></span>
                        <button type="button" class="button section-reset"
                                data-section="footer_text"
                                title="<?php esc_attr_e('Reset this section to defaults', 'alttag-registrations'); ?>">
                            <span class="dashicons dashicons-image-rotate"></span>
                            <?php _e('Reset', 'alttag-registrations'); ?>
                        </button>
                        <div class="form-check form-switch ms-auto mb-0">
                            <input class="form-check-input settings-input" type="checkbox"
                                   role="switch"
                                   name="footer_text[enabled]"
                                   id="footer_text_enabled"
                                   value="1"
                                   <?php checked(!empty($ft['enabled'])); ?>>
                            <label class="form-check-label" for="footer_text_enabled"><?php _e('Enable', 'alttag-registrations'); ?></label>
                        </div>
                    </h2>
                    <p class="description">
                        <?php _e('Fixed text block that always renders at the very bottom of the ticket (e.g. legal info / GDPR notice).', 'alttag-registrations'); ?>
                    </p>

                    <div class="footer-text-body">
                        <div class="form-group">
                            <label><?php _e('Text', 'alttag-registrations'); ?></label>
                            <?php
                            wp_editor(
                                isset($ft['text']) ? $ft['text'] : '',
                                'footer_text_content',
                                [
                                    'textarea_name' => 'footer_text[text]',
                                    'textarea_rows' => 8,
                                    'media_buttons' => false,
                                    'teeny'         => false,
                                    'quicktags'     => false,
                                    'tinymce'       => [
                                        'toolbar1' => 'bold,italic,underline,|,bullist,numlist,|,undo,redo',
                                        'toolbar2' => '',
                                        'menubar'  => false,
                                    ],
                                ]
                            );
                            ?>
                            <p class="description">
                                <?php
                                _e('Supports basic formatting (bold, italic, underline, lists). Shortcodes:', 'alttag-registrations');
                                $shortcodes = apply_filters('alttag_registrations_ticket_available_shortcodes', [
                                    '{event_name}', '{event_date}', '{event_time}', '{event_location}',
                                    '{product_name}', '{participant_name}', '{participant_email}',
                                ]);
                                echo ' ' . implode(', ', array_map(function ($s) {
                                    return '<code>' . esc_html($s) . '</code>';
                                }, $shortcodes));
                                ?>
                            </p>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label><?php _e('Font Size (px)', 'alttag-registrations'); ?></label>
                                <input type="number" name="footer_text[font_size]"
                                       value="<?php echo esc_attr($ft['font_size'] ?? 12); ?>"
                                       class="settings-input" step="1" min="4">
                            </div>
                            <div class="form-group">
                                <label><?php _e('Color', 'alttag-registrations'); ?></label>
                                <input type="text" name="footer_text[color]"
                                       value="<?php echo esc_attr($ft['color'] ?? '#000000'); ?>"
                                       class="color-picker settings-input">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label><?php _e('X Position (px)', 'alttag-registrations'); ?></label>
                                <input type="number" name="footer_text[x]"
                                       value="<?php echo esc_attr($ft['x'] ?? 97); ?>"
                                       class="settings-input" step="1">
                            </div>
                            <div class="form-group">
                                <label><?php _e('Width (px)', 'alttag-registrations'); ?></label>
                                <input type="number" name="footer_text[width]"
                                       value="<?php echo esc_attr($ft['width'] ?? 0); ?>"
                                       class="settings-input" step="1"
                                       placeholder="<?php esc_attr_e('0 = auto full-width', 'alttag-registrations'); ?>">
                            </div>
                        </div>
                    </div>

                    <script>
                    (function ($) {
                        $(function () {
                            var $s = $('#footer-text-section');
                            var $body = $s.find('.footer-text-body');
                            var $en = $('#footer_text_enabled');
                            function toggle() {
                                $body.css('opacity', $en.is(':checked') ? '1' : '0.4');
                                $body.find('input, textarea').prop('disabled', !$en.is(':checked'));
                                $en.prop('disabled', false);
                            }
                            $en.on('change', toggle);
                            toggle();
                        });
                    })(jQuery);
                    </script>
                </div>

                    </sl-tab-panel>
                </sl-tab-group>

                <div class="form-actions">
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <button type="submit" class="button button-primary button-large">
                            <?php _e('Save Settings', 'alttag-registrations'); ?>
                        </button>
                        <button type="button" class="button button-secondary" onclick="exportTicketSettings()">
                            <span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
                            <?php _e('Export Settings', 'alttag-registrations'); ?>
                        </button>
                        <label for="import-settings-file" class="button button-secondary" style="margin: 0; cursor: pointer;">
                            <span class="dashicons dashicons-upload" style="margin-top: 3px;"></span>
                            <?php _e('Import Settings', 'alttag-registrations'); ?>
                        </label>
                        <input type="file" id="import-settings-file" accept=".json" onchange="importTicketSettings(event)" style="display: none;">
                    </div>
                </div>
            </form>
        </div>

        <!-- Preview Panel -->
        <div class="ticket-designer-preview">
            <div class="preview-header">
                <h2><?php _e('Live Preview', 'alttag-registrations'); ?></h2>
                <button type="button" id="refresh-preview" class="button">
                    <?php _e('Refresh Preview', 'alttag-registrations'); ?>
                </button>
            </div>
            <div class="preview-container">
                <div id="preview-loading" class="preview-loading">
                    <span class="spinner is-active"></span>
                    <p><?php _e('Generating preview...', 'alttag-registrations'); ?></p>
                </div>
                <iframe id="ticket-preview-frame" src="about:blank" frameborder="0"></iframe>
            </div>
        </div>
    </div>
</div>

<!-- Content Row Template (hidden) -->
<script type="text/html" id="content-row-template">
    <?php
    $index = '{{INDEX}}';
    $row = [
        'type' => 'text',
        'text' => '',
        'color' => '#000000',
        'font_size' => 12,
        'width' => 0,
        'x' => 0,
        'y' => 0,
        'image_url' => '',
        'height' => 0,
    ];
    include ALTTAG_REGISTRATIONS_PATH . '/templates/admin/partials/content-row-item.php';
    ?>
</script>
