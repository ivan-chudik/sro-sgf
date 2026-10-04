<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="content-row-item" data-index="<?php echo esc_attr($index); ?>">
    <div class="content-row-header">
        <select name="content_rows[<?php echo esc_attr($index); ?>][type]" class="content-row-type settings-input">
            <option value="text" <?php selected($row['type'], 'text'); ?>>
                <?php _e('Text Block', 'alttag-registrations'); ?>
            </option>
            <option value="image" <?php selected($row['type'], 'image'); ?>>
                <?php _e('Image', 'alttag-registrations'); ?>
            </option>
            <option value="logo_row" <?php selected($row['type'], 'logo_row'); ?>>
                <?php _e('Logo Row', 'alttag-registrations'); ?>
            </option>
            <option value="qr_code" <?php selected($row['type'], 'qr_code'); ?>>
                <?php _e('QR Code', 'alttag-registrations'); ?>
            </option>
            <option value="pill" <?php selected($row['type'], 'pill'); ?>>
                <?php _e('Pill (title on colored bar)', 'alttag-registrations'); ?>
            </option>
        </select>
        <button type="button" class="button duplicate-content-row">
            <?php _e('Duplicate', 'alttag-registrations'); ?>
        </button>
        <button type="button" class="button remove-content-row">
            <?php _e('Remove', 'alttag-registrations'); ?>
        </button>
    </div>

    <!-- Text Fields -->
    <div class="content-row-fields content-row-text" style="<?php echo $row['type'] !== 'text' ? 'display:none;' : ''; ?>">
        <div class="form-group">
            <label><?php _e('Text', 'alttag-registrations'); ?></label>
            <?php
            $editor_id = 'content_row_text_' . esc_attr($index);
$editor_name = 'content_rows[' . esc_attr($index) . '][text]';
$content = isset($row['text']) ? $row['text'] : '';

// Use simple textarea for template, wp_editor for actual rows
if ($index === '{{INDEX}}') {
    ?>
                <textarea id="<?php echo $editor_id; ?>"
                          name="<?php echo $editor_name; ?>"
                          rows="5"
                          class="large-text"><?php echo esc_textarea($content); ?></textarea>
                <?php
} else {
    wp_editor($content, $editor_id, [
        'textarea_name' => $editor_name,
        'textarea_rows' => 5,
        'media_buttons' => false,
        'teeny' => false,
        'quicktags' => false,
        'tinymce' => [
            'toolbar1' => 'bold,italic,|,undo,redo',
            'toolbar2' => '',
            'menubar' => false,
        ],
    ]);
}
?>
            <p class="description">
                <?php
    _e('Available shortcodes:', 'alttag-registrations');
$shortcodes = apply_filters('alttag_registrations_ticket_available_shortcodes', [
    '{event_name}',
    '{event_date}',
    '{event_time}',
    '{event_location}',
    '{product_name}',
    '{participant_name}',
    '{participant_email}',
]);
echo ' ' . implode(', ', array_map(function ($s) {
    return '<code>' . esc_html($s) . '</code>';
}, $shortcodes));
?>
            </p>
        </div>
        <?php
        $has_custom_text = (isset($row['font_size']) && $row['font_size'] > 0 && $row['font_size'] != 12)
            || (!empty($row['color']) && strtolower($row['color']) !== '#000000')
            || (isset($row['width']) && $row['width'] > 0)
            || (isset($row['x']) && $row['x'] > 0)
            || (isset($row['y']) && $row['y'] > 0)
            || (isset($row['padding_top']) && $row['padding_top'] != 5)
            || (isset($row['padding_bottom']) && $row['padding_bottom'] != 5);
?>
        <details class="content-row-advanced">
            <summary>
                <span class="summary-label"><?php _e('Customize (font, color, spacing, position)', 'alttag-registrations'); ?></span>
                <button type="button" class="button-link block-reset" data-row-type="text"
                        title="<?php esc_attr_e('Reset all customizations to Auto', 'alttag-registrations'); ?>">
                    <span class="dashicons dashicons-image-rotate"></span>
                    <?php _e('Reset', 'alttag-registrations'); ?>
                </button>
            </summary>
            <div class="form-row">
                <div class="form-group">
                    <label><?php _e('Padding Top (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][padding_top]"
                           value="<?php echo isset($row['padding_top']) ? esc_attr($row['padding_top']) : 20; ?>"
                           class="small-text settings-input" step="1" min="0">
                </div>
                <div class="form-group">
                    <label><?php _e('Padding Bottom (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][padding_bottom]"
                           value="<?php echo isset($row['padding_bottom']) ? esc_attr($row['padding_bottom']) : 20; ?>"
                           class="small-text settings-input" step="1" min="0">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label><?php _e('Font Size (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][font_size]"
                           value="<?php echo isset($row['font_size']) ? esc_attr($row['font_size']) : 12; ?>"
                           class="small-text settings-input" step="1">
                </div>
                <div class="form-group">
                    <label><?php _e('Color', 'alttag-registrations'); ?></label>
                    <input type="text"
                           name="content_rows[<?php echo esc_attr($index); ?>][color]"
                           value="<?php echo isset($row['color']) ? esc_attr($row['color']) : '#000000'; ?>"
                           class="color-picker settings-input">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label><?php _e('Width (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][width]"
                           value="<?php echo isset($row['width']) ? esc_attr($row['width']) : 0; ?>"
                           class="small-text settings-input"
                           step="1"
                           placeholder="<?php _e('Auto (full width)', 'alttag-registrations'); ?>">
                    <p class="description"><?php _e('Leave as 0 for full width', 'alttag-registrations'); ?></p>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label><?php _e('X Position (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][x]"
                           value="<?php echo isset($row['x']) ? esc_attr($row['x']) : 0; ?>"
                           class="small-text settings-input"
                           step="1"
                           placeholder="<?php esc_attr_e('Auto', 'alttag-registrations'); ?>">
                </div>
                <div class="form-group">
                    <label><?php _e('Y Position (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][y]"
                           value="<?php echo isset($row['y']) ? esc_attr($row['y']) : 0; ?>"
                           class="small-text settings-input"
                           step="1"
                           placeholder="<?php esc_attr_e('Auto', 'alttag-registrations'); ?>">
                </div>
            </div>
        </details>
    </div>

    <!-- Image Fields -->
    <div class="content-row-fields content-row-image" style="<?php echo $row['type'] !== 'image' ? 'display:none;' : ''; ?>">
        <div class="form-group">
            <label><?php _e('Image', 'alttag-registrations'); ?></label>
            <div class="image-picker-row">
                <button type="button"
                        class="logo-preview-btn image-preview-btn upload-media-button"
                        data-target="content-row-image-<?php echo esc_attr($index); ?>"
                        title="<?php esc_attr_e('Click to change image', 'alttag-registrations'); ?>">
                    <?php if (!empty($row['image_url'])): ?>
                        <img class="logo-preview-img" src="<?php echo esc_url($row['image_url']); ?>" alt="">
                    <?php else: ?>
                        <span class="logo-preview-empty dashicons dashicons-format-image"></span>
                    <?php endif; ?>
                </button>
                <div class="logo-url-field">
                    <input type="text"
                           name="content_rows[<?php echo esc_attr($index); ?>][image_url]"
                           id="content-row-image-<?php echo esc_attr($index); ?>"
                           value="<?php echo isset($row['image_url']) ? esc_attr($row['image_url']) : ''; ?>"
                           class="content-row-image-url settings-input"
                           data-index="<?php echo esc_attr($index); ?>"
                           placeholder="<?php esc_attr_e('Image URL — click the tile to browse', 'alttag-registrations'); ?>">
                </div>
            </div>
        </div>
        <?php
$has_custom_image = (isset($row['alignment']) && $row['alignment'] !== 'centered')
    || (isset($row['x']) && $row['x'] > 0)
    || (isset($row['y']) && $row['y'] > 0)
    || (isset($row['width']) && $row['width'] > 0)
    || (isset($row['height']) && $row['height'] > 0)
    || (isset($row['padding_top']) && $row['padding_top'] != 5)
    || (isset($row['padding_bottom']) && $row['padding_bottom'] != 5);
?>
        <details class="content-row-advanced">
            <summary>
                <span class="summary-label"><?php _e('Customize (alignment, size, spacing, position)', 'alttag-registrations'); ?></span>
                <button type="button" class="button-link block-reset" data-row-type="image"
                        title="<?php esc_attr_e('Reset all customizations to Auto', 'alttag-registrations'); ?>">
                    <span class="dashicons dashicons-image-rotate"></span>
                    <?php _e('Reset', 'alttag-registrations'); ?>
                </button>
            </summary>
            <div class="form-row">
                <div class="form-group">
                    <label><?php _e('Padding Top (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][padding_top]"
                           value="<?php echo isset($row['padding_top']) ? esc_attr($row['padding_top']) : 20; ?>"
                           class="small-text settings-input" step="1" min="0">
                </div>
                <div class="form-group">
                    <label><?php _e('Padding Bottom (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][padding_bottom]"
                           value="<?php echo isset($row['padding_bottom']) ? esc_attr($row['padding_bottom']) : 20; ?>"
                           class="small-text settings-input" step="1" min="0">
                </div>
            </div>
            <div class="form-group">
                <label><?php _e('Alignment', 'alttag-registrations'); ?></label>
                <select name="content_rows[<?php echo esc_attr($index); ?>][alignment]"
                        class="settings-input image-alignment-select"
                        data-index="<?php echo esc_attr($index); ?>">
                    <option value="centered" <?php selected(isset($row['alignment']) ? $row['alignment'] : 'centered', 'centered'); ?>>
                        <?php _e('Centered', 'alttag-registrations'); ?>
                    </option>
                    <option value="absolute" <?php selected(isset($row['alignment']) ? $row['alignment'] : '', 'absolute'); ?>>
                        <?php _e('Absolute Position', 'alttag-registrations'); ?>
                    </option>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group absolute-position-fields" style="<?php echo (isset($row['alignment']) && $row['alignment'] === 'absolute') ? '' : 'display:none;'; ?>">
                    <label><?php _e('X Position (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][x]"
                           value="<?php echo isset($row['x']) ? esc_attr($row['x']) : 0; ?>"
                           class="small-text settings-input" step="1">
                </div>
                <div class="form-group">
                    <label><?php _e('Y Position (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][y]"
                           value="<?php echo isset($row['y']) ? esc_attr($row['y']) : 0; ?>"
                           class="small-text settings-input" step="1"
                           placeholder="<?php esc_attr_e('Auto', 'alttag-registrations'); ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label><?php _e('Width (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][width]"
                           value="<?php echo isset($row['width']) ? esc_attr($row['width']) : 0; ?>"
                           class="small-text settings-input" step="1"
                           placeholder="<?php esc_attr_e('Auto', 'alttag-registrations'); ?>">
                </div>
                <div class="form-group">
                    <label><?php _e('Height (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][height]"
                           value="<?php echo isset($row['height']) ? esc_attr($row['height']) : 0; ?>"
                           class="small-text settings-input" step="1"
                           placeholder="<?php esc_attr_e('Auto', 'alttag-registrations'); ?>">
                </div>
            </div>
        </details>
    </div>

    <!-- Logo Row Fields -->
    <div class="content-row-fields content-row-logo_row" style="<?php echo $row['type'] !== 'logo_row' ? 'display:none;' : ''; ?>">

        <div class="logos-repeater" data-row-index="<?php echo esc_attr($index); ?>">
            <label><?php _e('Logos', 'alttag-registrations'); ?></label>
            <div class="logo-items-container">
                <?php
        $logos = isset($row['logos']) && is_array($row['logos']) ? $row['logos'] : [];
if (empty($logos)) {
    $logos = [['image_url' => '', 'x' => 0]];
}
foreach ($logos as $logo_index => $logo):
    ?>
                <div class="logo-item" data-logo-index="<?php echo esc_attr($logo_index); ?>">
                    <span class="logo-drag-handle dashicons dashicons-menu" title="<?php esc_attr_e('Drag to reorder', 'alttag-registrations'); ?>"></span>
                    <button type="button"
                            class="logo-preview-btn upload-logo-button"
                            data-row-index="<?php echo esc_attr($index); ?>"
                            data-logo-index="<?php echo esc_attr($logo_index); ?>"
                            title="<?php esc_attr_e('Click to change image', 'alttag-registrations'); ?>">
                        <?php if (!empty($logo['image_url'])): ?>
                            <img class="logo-preview-img" src="<?php echo esc_url($logo['image_url']); ?>" alt="">
                        <?php else: ?>
                            <span class="logo-preview-empty dashicons dashicons-format-image"></span>
                        <?php endif; ?>
                    </button>
                    <div class="logo-url-field">
                        <input type="text"
                               name="content_rows[<?php echo esc_attr($index); ?>][logos][][image_url]"
                               value="<?php echo isset($logo['image_url']) ? esc_attr($logo['image_url']) : ''; ?>"
                               class="logo-image-url settings-input"
                               placeholder="<?php esc_attr_e('Image URL — click the tile above to browse', 'alttag-registrations'); ?>">
                    </div>
                    <button type="button" class="button remove-logo-button" title="<?php esc_attr_e('Remove logo', 'alttag-registrations'); ?>">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button add-logo-button" data-row-index="<?php echo esc_attr($index); ?>">
                <?php _e('Add Logo', 'alttag-registrations'); ?>
            </button>
        </div>

        <?php
        $has_custom_lr = (isset($row['y']) && $row['y'] > 0)
            || (isset($row['width']) && $row['width'] > 0)
            || (isset($row['height']) && $row['height'] > 0)
            || (isset($row['spacing']) && $row['spacing'] !== '' && $row['spacing'] !== null)
            || (isset($row['padding_top']) && $row['padding_top'] != 5)
            || (isset($row['padding_bottom']) && $row['padding_bottom'] != 5);
?>
        <details class="content-row-advanced">
            <summary>
                <span class="summary-label"><?php _e('Customize (dimensions, spacing, position)', 'alttag-registrations'); ?></span>
                <button type="button" class="button-link block-reset" data-row-type="logo_row"
                        title="<?php esc_attr_e('Reset all customizations to Auto', 'alttag-registrations'); ?>">
                    <span class="dashicons dashicons-image-rotate"></span>
                    <?php _e('Reset', 'alttag-registrations'); ?>
                </button>
            </summary>
            <div class="form-row">
                <div class="form-group">
                    <label><?php _e('Padding Top (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][padding_top]"
                           value="<?php echo isset($row['padding_top']) ? esc_attr($row['padding_top']) : 20; ?>"
                           class="small-text settings-input" step="1" min="0">
                </div>
                <div class="form-group">
                    <label><?php _e('Padding Bottom (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][padding_bottom]"
                           value="<?php echo isset($row['padding_bottom']) ? esc_attr($row['padding_bottom']) : 20; ?>"
                           class="small-text settings-input" step="1" min="0">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label><?php _e('Y Position (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][y]"
                           value="<?php echo isset($row['y']) ? esc_attr($row['y']) : 0; ?>"
                           class="small-text settings-input" step="1"
                           placeholder="<?php esc_attr_e('Auto', 'alttag-registrations'); ?>">
                </div>
                <div class="form-group">
                    <label><?php _e('Logo Width (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][width]"
                           value="<?php echo isset($row['width']) ? esc_attr($row['width']) : 0; ?>"
                           class="small-text settings-input" step="1"
                           placeholder="<?php esc_attr_e('Auto', 'alttag-registrations'); ?>">
                </div>
                <div class="form-group">
                    <label><?php _e('Logo Height (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][height]"
                           value="<?php echo isset($row['height']) ? esc_attr($row['height']) : 0; ?>"
                           class="small-text settings-input" step="1"
                           placeholder="<?php esc_attr_e('Auto', 'alttag-registrations'); ?>">
                </div>
                <div class="form-group">
                    <label><?php _e('Spacing (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][spacing]"
                           value="<?php echo isset($row['spacing']) ? esc_attr($row['spacing']) : ''; ?>"
                           class="small-text settings-input"
                           placeholder="<?php esc_attr_e('Auto', 'alttag-registrations'); ?>" step="1">
                </div>
            </div>
        </details>
    </div>

    <!-- Pill (title on colored bar) Fields -->
    <div class="content-row-fields content-row-pill" style="<?php echo $row['type'] !== 'pill' ? 'display:none;' : ''; ?>">
        <div class="form-group">
            <label><?php _e('Title', 'alttag-registrations'); ?></label>
            <input type="text"
                   name="content_rows[<?php echo esc_attr($index); ?>][text]"
                   value="<?php echo isset($row['text']) ? esc_attr($row['text']) : ''; ?>"
                   class="regular-text settings-input"
                   placeholder="<?php _e('e.g. PARTNERS', 'alttag-registrations'); ?>">
        </div>
        <details class="content-row-advanced">
            <summary>
                <span class="summary-label"><?php _e('Customize (colors, size, spacing, position)', 'alttag-registrations'); ?></span>
                <button type="button" class="button-link block-reset" data-row-type="pill"
                        title="<?php esc_attr_e('Reset all customizations to Auto', 'alttag-registrations'); ?>">
                    <span class="dashicons dashicons-image-rotate"></span>
                    <?php _e('Reset', 'alttag-registrations'); ?>
                </button>
            </summary>
            <div class="form-row">
                <div class="form-group">
                    <label><?php _e('Padding Top (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][padding_top]"
                           value="<?php echo isset($row['padding_top']) ? esc_attr($row['padding_top']) : 20; ?>"
                           class="small-text settings-input" step="1" min="0">
                </div>
                <div class="form-group">
                    <label><?php _e('Padding Bottom (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][padding_bottom]"
                           value="<?php echo isset($row['padding_bottom']) ? esc_attr($row['padding_bottom']) : 20; ?>"
                           class="small-text settings-input" step="1" min="0">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label><?php _e('Background color', 'alttag-registrations'); ?></label>
                    <input type="text"
                           name="content_rows[<?php echo esc_attr($index); ?>][bg_color]"
                           value="<?php echo isset($row['bg_color']) ? esc_attr($row['bg_color']) : '#9C27B0'; ?>"
                           class="color-picker settings-input">
                </div>
                <div class="form-group">
                    <label><?php _e('Text color', 'alttag-registrations'); ?></label>
                    <input type="text"
                           name="content_rows[<?php echo esc_attr($index); ?>][color]"
                           value="<?php echo isset($row['color']) ? esc_attr($row['color']) : '#FFFFFF'; ?>"
                           class="color-picker settings-input">
                </div>
                <div class="form-group">
                    <label><?php _e('Font size (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][font_size]"
                           value="<?php echo isset($row['font_size']) ? esc_attr($row['font_size']) : 18; ?>"
                           class="small-text settings-input" step="1">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label><?php _e('Width (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][width]"
                           value="<?php echo isset($row['width']) ? esc_attr($row['width']) : 400; ?>"
                           class="small-text settings-input" step="1">
                </div>
                <div class="form-group">
                    <label><?php _e('Height (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][height]"
                           value="<?php echo isset($row['height']) ? esc_attr($row['height']) : 60; ?>"
                           class="small-text settings-input" step="1">
                </div>
                <div class="form-group">
                    <label><?php _e('Border radius (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][border_radius]"
                           value="<?php echo isset($row['border_radius']) ? esc_attr($row['border_radius']) : 30; ?>"
                           class="small-text settings-input" step="1"
                           placeholder="<?php _e('Half of height = full pill', 'alttag-registrations'); ?>">
                </div>
            </div>
            <div class="form-group">
                <label><?php _e('Alignment', 'alttag-registrations'); ?></label>
                <select name="content_rows[<?php echo esc_attr($index); ?>][alignment]"
                        class="settings-input pill-alignment-select"
                        data-index="<?php echo esc_attr($index); ?>">
                    <option value="centered" <?php selected(isset($row['alignment']) ? $row['alignment'] : 'centered', 'centered'); ?>>
                        <?php _e('Centered', 'alttag-registrations'); ?>
                    </option>
                    <option value="absolute" <?php selected(isset($row['alignment']) ? $row['alignment'] : '', 'absolute'); ?>>
                        <?php _e('Absolute position', 'alttag-registrations'); ?>
                    </option>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group pill-absolute-position-fields" style="<?php echo (isset($row['alignment']) && $row['alignment'] === 'absolute') ? '' : 'display:none;'; ?>">
                    <label><?php _e('X Position (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][x]"
                           value="<?php echo isset($row['x']) ? esc_attr($row['x']) : 0; ?>"
                           class="small-text settings-input" step="1">
                </div>
                <div class="form-group">
                    <label><?php _e('Y Position (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][y]"
                           value="<?php echo isset($row['y']) ? esc_attr($row['y']) : 0; ?>"
                           class="small-text settings-input" step="1"
                           placeholder="<?php esc_attr_e('Auto', 'alttag-registrations'); ?>">
                </div>
            </div>
        </details>
    </div>

    <!-- QR Code Fields -->
    <div class="content-row-fields content-row-qr_code" style="<?php echo $row['type'] !== 'qr_code' ? 'display:none;' : ''; ?>">
        <div class="form-group">
            <label><?php _e('QR Code Content (URL or text)', 'alttag-registrations'); ?></label>
            <input type="text"
                   name="content_rows[<?php echo esc_attr($index); ?>][qr_content]"
                   value="<?php echo isset($row['qr_content']) ? esc_attr($row['qr_content']) : ''; ?>"
                   class="regular-text settings-input"
                   placeholder="<?php _e('https://example.com or any text', 'alttag-registrations'); ?>">
        </div>
        <details class="content-row-advanced">
            <summary>
                <span class="summary-label"><?php _e('Customize (position, size, frame, title label, spacing)', 'alttag-registrations'); ?></span>
                <button type="button" class="button-link block-reset" data-row-type="qr_code"
                        title="<?php esc_attr_e('Reset all customizations to Auto', 'alttag-registrations'); ?>">
                    <span class="dashicons dashicons-image-rotate"></span>
                    <?php _e('Reset', 'alttag-registrations'); ?>
                </button>
            </summary>
            <div class="form-row">
                <div class="form-group">
                    <label><?php _e('Padding Top (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][padding_top]"
                           value="<?php echo isset($row['padding_top']) ? esc_attr($row['padding_top']) : 20; ?>"
                           class="small-text settings-input" step="1" min="0">
                </div>
                <div class="form-group">
                    <label><?php _e('Padding Bottom (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][padding_bottom]"
                           value="<?php echo isset($row['padding_bottom']) ? esc_attr($row['padding_bottom']) : 20; ?>"
                           class="small-text settings-input" step="1" min="0">
                </div>
                <div class="form-group">
                    <label><?php esc_html_e('Padding left (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][padding_left]"
                           value="<?php echo isset($row['padding_left']) ? esc_attr($row['padding_left']) : 0; ?>"
                           class="small-text settings-input" step="1" min="0">
                </div>
                <div class="form-group">
                    <label><?php esc_html_e('Padding right (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][padding_right]"
                           value="<?php echo isset($row['padding_right']) ? esc_attr($row['padding_right']) : 0; ?>"
                           class="small-text settings-input" step="1" min="0">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label><?php _e('X Position (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][x]"
                           value="<?php echo isset($row['x']) ? esc_attr($row['x']) : 0; ?>"
                           class="small-text settings-input" step="1"
                           placeholder="<?php esc_attr_e('Auto', 'alttag-registrations'); ?>">
                </div>
                <div class="form-group">
                    <label><?php _e('Y Position (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][y]"
                           value="<?php echo isset($row['y']) ? esc_attr($row['y']) : 0; ?>"
                           class="small-text settings-input" step="1"
                           placeholder="<?php esc_attr_e('Auto', 'alttag-registrations'); ?>">
                </div>
                <div class="form-group">
                    <label><?php _e('Size (px)', 'alttag-registrations'); ?></label>
                    <input type="number"
                           name="content_rows[<?php echo esc_attr($index); ?>][size]"
                           value="<?php echo isset($row['size']) ? esc_attr($row['size']) : 270; ?>"
                           class="small-text settings-input" step="1">
                </div>
            </div>
            <div class="form-check form-switch inline-next-toggle">
                <input class="form-check-input settings-input" type="checkbox" role="switch"
                       name="content_rows[<?php echo esc_attr($index); ?>][inline_next]"
                       id="qr_inline_next_<?php echo esc_attr($index); ?>"
                       value="1"
                       <?php checked(!empty($row['inline_next'])); ?>>
                <label class="form-check-label" for="qr_inline_next_<?php echo esc_attr($index); ?>">
                    <?php _e('Render next block inline (side by side)', 'alttag-registrations'); ?>
                </label>
            </div>
            <hr style="margin:16px 0;">
            <p style="font-weight:600; margin:0 0 8px;"><?php _e('Frame (border around QR)', 'alttag-registrations'); ?></p>
        <div class="form-check form-switch inline-next-toggle">
            <input class="form-check-input settings-input" type="checkbox" role="switch"
                   name="content_rows[<?php echo esc_attr($index); ?>][border_enabled]"
                   id="qr_border_enabled_<?php echo esc_attr($index); ?>"
                   value="1"
                   <?php checked(!empty($row['border_enabled'])); ?>>
            <label class="form-check-label" for="qr_border_enabled_<?php echo esc_attr($index); ?>">
                <?php _e('Draw frame around QR', 'alttag-registrations'); ?>
            </label>
        </div>
        <div class="form-group">
            <label><?php _e('Frame color', 'alttag-registrations'); ?></label>
            <input type="text"
                   name="content_rows[<?php echo esc_attr($index); ?>][border_color]"
                   value="<?php echo isset($row['border_color']) ? esc_attr($row['border_color']) : '#2B5C63'; ?>"
                   class="color-picker settings-input">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label><?php _e('Frame width (px)', 'alttag-registrations'); ?></label>
                <input type="number"
                       name="content_rows[<?php echo esc_attr($index); ?>][border_width]"
                       value="<?php echo isset($row['border_width']) ? esc_attr($row['border_width']) : 4; ?>"
                       class="small-text settings-input" step="1" min="0">
            </div>
            <div class="form-group">
                <label><?php _e('Frame radius (px)', 'alttag-registrations'); ?></label>
                <input type="number"
                       name="content_rows[<?php echo esc_attr($index); ?>][border_radius]"
                       value="<?php echo isset($row['border_radius']) ? esc_attr($row['border_radius']) : 20; ?>"
                       class="small-text settings-input" step="1" min="0">
            </div>
            <div class="form-group">
                <label><?php _e('Padding (px)', 'alttag-registrations'); ?></label>
                <input type="number"
                       name="content_rows[<?php echo esc_attr($index); ?>][padding]"
                       value="<?php echo isset($row['padding']) ? esc_attr($row['padding']) : 20; ?>"
                       class="small-text settings-input" step="1" min="0">
            </div>
        </div>
        <hr style="margin:16px 0;">
        <p style="font-weight:600; margin:0 0 8px;"><?php _e('Title label below QR', 'alttag-registrations'); ?></p>
        <div class="form-group">
            <label><?php _e('Title text', 'alttag-registrations'); ?></label>
            <input type="text"
                   name="content_rows[<?php echo esc_attr($index); ?>][title_text]"
                   value="<?php echo isset($row['title_text']) ? esc_attr($row['title_text']) : ''; ?>"
                   class="regular-text settings-input"
                   placeholder="<?php _e('e.g. PROGRAM (leave empty to hide)', 'alttag-registrations'); ?>">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label><?php _e('Pill background', 'alttag-registrations'); ?></label>
                <input type="text"
                       name="content_rows[<?php echo esc_attr($index); ?>][title_bg_color]"
                       value="<?php echo isset($row['title_bg_color']) ? esc_attr($row['title_bg_color']) : '#9C27B0'; ?>"
                       class="color-picker settings-input">
            </div>
            <div class="form-group">
                <label><?php _e('Pill text color', 'alttag-registrations'); ?></label>
                <input type="text"
                       name="content_rows[<?php echo esc_attr($index); ?>][title_color]"
                       value="<?php echo isset($row['title_color']) ? esc_attr($row['title_color']) : '#FFFFFF'; ?>"
                       class="color-picker settings-input">
            </div>
            <div class="form-group">
                <label><?php _e('Font size (px)', 'alttag-registrations'); ?></label>
                <input type="number"
                       name="content_rows[<?php echo esc_attr($index); ?>][title_font_size]"
                       value="<?php echo isset($row['title_font_size']) ? esc_attr($row['title_font_size']) : 11; ?>"
                       class="small-text settings-input" step="1">
            </div>
            <div class="form-group">
                <label><?php _e('Pill height (px)', 'alttag-registrations'); ?></label>
                <input type="number"
                       name="content_rows[<?php echo esc_attr($index); ?>][title_height]"
                       value="<?php echo isset($row['title_height']) ? esc_attr($row['title_height']) : 60; ?>"
                       class="small-text settings-input" step="1">
            </div>
        </div>
        </details>
    </div>
</div>
