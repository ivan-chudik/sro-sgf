<?php
/**
 * Plugin Name: Post Meta Editor
 * Description: View and edit post meta data directly from the post edit screen.
 * Version: 1.0.0
 * Author: Šimon Cihra
 */

if (!defined('ABSPATH')) {
    exit;
}

class PostMetaEditor
{
    public function __construct()
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox']);
        add_action('save_post', [$this, 'saveOnPostUpdate'], 99);
        add_action('wp_ajax_pme_save_meta', [$this, 'ajaxSaveMeta']);
        add_action('wp_ajax_pme_delete_meta', [$this, 'ajaxDeleteMeta']);
        add_action('wp_ajax_pme_add_meta', [$this, 'ajaxAddMeta']);
    }

    public function addMetaBox()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $post_types = get_post_types([], 'names');

        foreach ($post_types as $post_type) {
            add_meta_box(
                'post_meta_editor',
                'Post Meta Editor',
                [$this, 'renderMetaBox'],
                $post_type,
                'normal',
                'low'
            );
        }
    }

    public function renderMetaBox($post)
    {
        $all_meta = get_post_meta($post->ID);
        $nonce = wp_create_nonce('pme_nonce');

        ?>
        <style>
            .pme-table { width: 100%; border-collapse: collapse; font-size: 13px; }
            .pme-table th { text-align: left; padding: 8px 10px; background: #f0f0f1; border-bottom: 1px solid #c3c4c7; font-weight: 600; }
            .pme-table td { padding: 6px 10px; border-bottom: 1px solid #f0f0f1; vertical-align: top; }
            .pme-table tr:hover td { background: #f9f9f9; }
            .pme-table tr.pme-system td { color: #999; }
            .pme-table tr.pme-system:hover td { color: #666; }
            .pme-key { font-family: monospace; font-size: 12px; word-break: break-all; min-width: 180px; }
            .pme-value { font-family: monospace; font-size: 12px; word-break: break-all; max-width: 600px; }
            .pme-value textarea { width: 100%; min-height: 60px; font-family: monospace; font-size: 12px; resize: vertical; display: none; }
            .pme-value textarea.pme-active { display: block; }
            .pme-value .pme-display { cursor: pointer; white-space: pre-wrap; max-height: 200px; overflow-y: auto; padding: 4px; border: 1px solid transparent; border-radius: 3px; }
            .pme-value .pme-display:hover { border-color: #2271b1; background: #f0f7fd; }
            .pme-actions { white-space: nowrap; min-width: 100px; }
            .pme-actions button { margin-right: 4px; cursor: pointer; }
            .pme-btn { padding: 3px 8px; font-size: 12px; border: 1px solid #c3c4c7; border-radius: 3px; background: #f6f7f7; cursor: pointer; }
            .pme-btn:hover { background: #f0f0f1; border-color: #8c8f94; }
            .pme-btn-save { background: #2271b1; color: #fff; border-color: #2271b1; }
            .pme-btn-save:hover { background: #135e96; }
            .pme-btn-cancel { }
            .pme-btn-delete { color: #b32d2e; border-color: #b32d2e; }
            .pme-btn-delete:hover { background: #b32d2e; color: #fff; }
            .pme-btn-add { background: #2271b1; color: #fff; border-color: #2271b1; }
            .pme-btn-add:hover { background: #135e96; }
            .pme-add-row td { background: #f0f7fd; }
            .pme-add-row input, .pme-add-row textarea { width: 100%; font-family: monospace; font-size: 12px; }
            .pme-add-row textarea { min-height: 40px; resize: vertical; }
            .pme-notice { padding: 8px 12px; margin: 8px 0; border-left: 4px solid #00a32a; background: #f0f6e8; display: none; }
            .pme-notice.pme-error { border-color: #d63638; background: #fcf0f1; }
            .pme-search { margin-bottom: 10px; }
            .pme-search input { width: 300px; padding: 4px 8px; }
            .pme-count { color: #666; font-size: 12px; margin-left: 10px; }
        </style>

        <input type="hidden" name="pme_nonce" value="<?php echo $nonce; ?>" />

        <div class="pme-notice" id="pme-notice"></div>

        <div class="pme-search">
            <input type="text" id="pme-search" placeholder="Hľadať meta key alebo value..." />
            <span class="pme-count" id="pme-count"><?php echo count($all_meta); ?> polí</span>
        </div>

        <table class="pme-table">
            <thead>
                <tr>
                    <th>Meta Key</th>
                    <th>Meta Value</th>
                    <th>Akcie</th>
                </tr>
            </thead>
            <tbody id="pme-tbody">
                <?php
                ksort($all_meta);
                foreach ($all_meta as $key => $values) :
                    $value = $values[0];
                    $is_system = strpos($key, '_') === 0;
                    $is_serialized = is_serialized($value);
                    $unserialized = $is_serialized ? maybe_unserialize($value) : null;
                    $is_structured = $is_serialized && (is_array($unserialized) || is_object($unserialized));

                    $json_flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
                    if ($is_structured) {
                        $editable_value = wp_json_encode($unserialized, $json_flags);
                        $display_value = $editable_value;
                    } elseif ($is_serialized) {
                        $editable_value = $value;
                        $display_value = print_r($unserialized, true);
                    } else {
                        $editable_value = $value;
                        $display_value = $value;
                    }

                    $row_classes = 'pme-row';
                    if ($is_system) {
                        $row_classes .= ' pme-system';
                    }
                    if ($is_structured) {
                        $row_classes .= ' pme-structured';
                    }
                    ?>
                    <tr class="<?php echo esc_attr($row_classes); ?>" data-key="<?php echo esc_attr($key); ?>">
                        <td class="pme-key">
                            <?php echo esc_html($key); ?>
                            <?php if ($is_structured) : ?>
                                <span style="color:#2271b1;font-size:10px;">[JSON]</span>
                            <?php endif; ?>
                        </td>
                        <td class="pme-value">
                            <div class="pme-display" title="Klikni pre editáciu"><?php echo esc_html($display_value); ?></div>
                            <textarea class="pme-textarea"><?php echo esc_textarea($editable_value); ?></textarea>
                        </td>
                        <td class="pme-actions">
                            <button type="button" class="pme-btn pme-btn-edit" title="Editovať">&#9998;</button>
                            <button type="button" class="pme-btn pme-btn-save" style="display:none" title="Uložiť">Uložiť</button>
                            <button type="button" class="pme-btn pme-btn-cancel" style="display:none" title="Zrušiť">Zrušiť</button>
                            <button type="button" class="pme-btn pme-btn-delete" title="Zmazať">&#128465;</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="pme-add-row">
                    <td><input type="text" id="pme-new-key" placeholder="meta_key" /></td>
                    <td><textarea id="pme-new-value" placeholder="meta_value"></textarea></td>
                    <td><button type="button" class="pme-btn pme-btn-add" id="pme-add-btn">+ Pridať</button></td>
                </tr>
            </tfoot>
        </table>

        <script>
        (function() {
            var postId = <?php echo (int) $post->ID; ?>;
            var nonce = '<?php echo $nonce; ?>';
            var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';

            function showNotice(msg, isError) {
                var el = document.getElementById('pme-notice');
                el.textContent = msg;
                el.className = 'pme-notice' + (isError ? ' pme-error' : '');
                el.style.display = 'block';
                setTimeout(function() { el.style.display = 'none'; }, 3000);
            }

            function ajaxRequest(action, data, callback) {
                data.action = action;
                data.nonce = nonce;
                data.post_id = postId;

                var fd = new FormData();
                for (var k in data) fd.append(k, data[k]);

                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(r) {
                        if (r.success) {
                            callback(null, r.data);
                        } else {
                            callback(r.data || 'Error');
                        }
                    })
                    .catch(function(e) { callback(e.message); });
            }

            // Search
            document.getElementById('pme-search').addEventListener('input', function() {
                var q = this.value.toLowerCase();
                var rows = document.querySelectorAll('.pme-row');
                var visible = 0;
                rows.forEach(function(row) {
                    var key = row.getAttribute('data-key').toLowerCase();
                    var val = row.querySelector('.pme-display').textContent.toLowerCase();
                    var show = !q || key.indexOf(q) !== -1 || val.indexOf(q) !== -1;
                    row.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                document.getElementById('pme-count').textContent = visible + ' / ' + rows.length + ' polí';
            });

            // Edit
            document.addEventListener('click', function(e) {
                var btn = e.target;

                // Edit button or display click
                if (btn.classList.contains('pme-btn-edit') || btn.classList.contains('pme-display')) {
                    var row = btn.closest('.pme-row');
                    if (!row) return;
                    var key = row.getAttribute('data-key');
                    var ta = row.querySelector('.pme-textarea');
                    row.querySelector('.pme-display').style.display = 'none';
                    ta.classList.add('pme-active');
                    ta.setAttribute('name', 'pme_meta[' + key + ']');
                    row.querySelector('.pme-btn-edit').style.display = 'none';
                    row.querySelector('.pme-btn-save').style.display = 'inline-block';
                    row.querySelector('.pme-btn-cancel').style.display = 'inline-block';
                    ta.focus();
                }

                // Save (AJAX)
                if (btn.classList.contains('pme-btn-save')) {
                    var row = btn.closest('.pme-row');
                    var key = row.getAttribute('data-key');
                    var ta = row.querySelector('.pme-textarea');
                    var val = ta.value;

                    ajaxRequest('pme_save_meta', { meta_key: key, meta_value: val }, function(err) {
                        if (err) {
                            showNotice('Chyba: ' + err, true);
                            return;
                        }
                        row.querySelector('.pme-display').textContent = val;
                        row.querySelector('.pme-display').style.display = '';
                        ta.classList.remove('pme-active');
                        ta.removeAttribute('name');
                        row.querySelector('.pme-btn-edit').style.display = '';
                        row.querySelector('.pme-btn-save').style.display = 'none';
                        row.querySelector('.pme-btn-cancel').style.display = 'none';
                        showNotice('Uložené: ' + key, false);
                    });
                }

                // Cancel
                if (btn.classList.contains('pme-btn-cancel')) {
                    var row = btn.closest('.pme-row');
                    var display = row.querySelector('.pme-display');
                    var ta = row.querySelector('.pme-textarea');
                    ta.value = display.textContent;
                    display.style.display = '';
                    ta.classList.remove('pme-active');
                    ta.removeAttribute('name');
                    row.querySelector('.pme-btn-edit').style.display = '';
                    row.querySelector('.pme-btn-save').style.display = 'none';
                    row.querySelector('.pme-btn-cancel').style.display = 'none';
                }

                // Delete
                if (btn.classList.contains('pme-btn-delete')) {
                    var row = btn.closest('.pme-row');
                    var key = row.getAttribute('data-key');
                    if (!confirm('Naozaj zmazať "' + key + '"?')) return;

                    ajaxRequest('pme_delete_meta', { meta_key: key }, function(err) {
                        if (err) {
                            showNotice('Chyba: ' + err, true);
                            return;
                        }
                        row.remove();
                        showNotice('Zmazané: ' + key, false);
                    });
                }
            });

            // Add
            document.getElementById('pme-add-btn').addEventListener('click', function() {
                var keyInput = document.getElementById('pme-new-key');
                var valInput = document.getElementById('pme-new-value');
                var key = keyInput.value.trim();
                var val = valInput.value;

                if (!key) {
                    showNotice('Zadaj meta key', true);
                    keyInput.focus();
                    return;
                }

                ajaxRequest('pme_add_meta', { meta_key: key, meta_value: val }, function(err) {
                    if (err) {
                        showNotice('Chyba: ' + err, true);
                        return;
                    }

                    var isSystem = key.charAt(0) === '_';
                    var tbody = document.getElementById('pme-tbody');
                    var tr = document.createElement('tr');
                    tr.className = 'pme-row' + (isSystem ? ' pme-system' : '');
                    tr.setAttribute('data-key', key);
                    tr.innerHTML =
                        '<td class="pme-key">' + key.replace(/</g, '&lt;') + '</td>' +
                        '<td class="pme-value">' +
                            '<div class="pme-display" title="Klikni pre editáciu">' + val.replace(/</g, '&lt;') + '</div>' +
                            '<textarea class="pme-textarea">' + val.replace(/</g, '&lt;') + '</textarea>' +
                        '</td>' +
                        '<td class="pme-actions">' +
                            '<button type="button" class="pme-btn pme-btn-edit" title="Editovať">&#9998;</button>' +
                            '<button type="button" class="pme-btn pme-btn-save" style="display:none" title="Uložiť">Uložiť</button>' +
                            '<button type="button" class="pme-btn pme-btn-cancel" style="display:none" title="Zrušiť">Zrušiť</button>' +
                            '<button type="button" class="pme-btn pme-btn-delete" title="Zmazať">&#128465;</button>' +
                        '</td>';
                    tbody.appendChild(tr);

                    keyInput.value = '';
                    valInput.value = '';
                    showNotice('Pridané: ' + key, false);
                });
            });
        })();
        </script>
        <?php
    }

    public function saveOnPostUpdate($post_id)
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!isset($_POST['pme_nonce']) || !wp_verify_nonce($_POST['pme_nonce'], 'pme_nonce')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (empty($_POST['pme_meta']) || !is_array($_POST['pme_meta'])) {
            return;
        }

        foreach ($_POST['pme_meta'] as $key => $value) {
            $key = sanitize_text_field($key);
            $value = $this->normalizeValue(wp_unslash($value));
            update_post_meta($post_id, $key, wp_slash($value));
        }
    }

    public function ajaxSaveMeta()
    {
        $this->verifyRequest();

        $post_id = intval($_POST['post_id']);
        $meta_key = sanitize_text_field($_POST['meta_key']);
        $meta_value = $this->normalizeValue(wp_unslash($_POST['meta_value']));

        update_post_meta($post_id, $meta_key, wp_slash($meta_value));
        wp_send_json_success('ok');
    }

    public function ajaxDeleteMeta()
    {
        $this->verifyRequest();

        $post_id = intval($_POST['post_id']);
        $meta_key = sanitize_text_field($_POST['meta_key']);

        delete_post_meta($post_id, $meta_key);
        wp_send_json_success('ok');
    }

    public function ajaxAddMeta()
    {
        $this->verifyRequest();

        $post_id = intval($_POST['post_id']);
        $meta_key = sanitize_text_field($_POST['meta_key']);
        $meta_value = $this->normalizeValue(wp_unslash($_POST['meta_value']));

        if (empty($meta_key)) {
            wp_send_json_error('Meta key je povinný');
        }

        add_post_meta($post_id, $meta_key, wp_slash($meta_value));
        wp_send_json_success('ok');
    }

    private function normalizeValue($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = ltrim($value);
        if (isset($trimmed[0]) && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
                return $decoded;
            }
        }

        if (is_serialized($value)) {
            return maybe_unserialize($value);
        }

        return $value;
    }

    private function verifyRequest()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Nedostatočné oprávnenia');
        }

        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'pme_nonce')) {
            wp_send_json_error('Neplatný nonce');
        }
    }
}

new PostMetaEditor();
