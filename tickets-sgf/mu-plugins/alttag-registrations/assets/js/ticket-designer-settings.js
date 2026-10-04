(function ($) {
    "use strict";

    let contentRowIndex = 0;
    let previewDebounceTimer = null;

    /**
     * Convert plain text with newlines to HTML paragraphs (like wpautop).
     * If text already has <p> tags, return as-is.
     */
    function textToHtml(text) {
        if (!text) return "";
        // Already has <p> tags - return as-is
        if (text.indexOf("<p>") !== -1 || text.indexOf("<p ") !== -1) {
            return text;
        }
        // Split by double newlines for paragraphs, single newlines for <br>
        var paragraphs = text.split(/\n\n+/);
        var html = paragraphs
            .map(function (p) {
                p = p.replace(/\n/g, "<br>");
                return "<p>" + p + "</p>";
            })
            .join("\n");
        return html;
    }

    $(document).ready(function () {
        initializeColorPickers();
        initializeMediaUploader();
        initializeContentRowRepeater();
        initializeLivePreview();
        initializeExistingEditors();
        initializeSortable();
        initializeContentRowStates();
        initializeTicketFieldOrderSortable();
        initializeAllLogoSortables();
        initializeSectionReset();
        initializeResetFieldLayout();
        initializeBlockReset();
        initializeAutoLayoutToggle();

        // Set initial content row index based on existing rows
        contentRowIndex = $(".content-row-item").length;
    });

    /**
     * Initialize TinyMCE for existing editors
     */
    function initializeExistingEditors() {
        if (typeof tinymce !== "undefined") {
            // Add change listener to existing TinyMCE editors
            tinymce.editors.forEach(function (editor) {
                if (
                    editor.id.startsWith("content_row_text_") ||
                    editor.id === "footer_text_content"
                ) {
                    editor.on("change keyup", function () {
                        triggerPreviewUpdate();
                    });
                }
            });
        }
    }

    /**
     * Initialize color pickers
     */
    function initializeColorPickers() {
        $(".color-picker").wpColorPicker({
            change: function () {
                triggerPreviewUpdate();
            },
        });

        // Re-initialize color pickers when new rows are added
        $(document).on("click", "#add-content-row", function () {
            setTimeout(function () {
                $(".color-picker").wpColorPicker({
                    change: function () {
                        triggerPreviewUpdate();
                    },
                });
            }, 100);
        });
    }

    /**
     * Initialize WordPress media uploader
     */
    function initializeMediaUploader() {
        $(document).on("click", ".upload-media-button", function (e) {
            e.preventDefault();

            const button = $(this);
            const targetId = button.data("target");
            let targetInput;
            let isContentRowImage = false;
            let rowIndex;

            if (targetId.startsWith("content-row-image-")) {
                isContentRowImage = true;
                rowIndex = targetId.replace("content-row-image-", "");
                targetInput = button.siblings(".content-row-image-url");
            } else {
                targetInput = $("#" + targetId);
            }

            const mediaUploader = wp.media({
                title: ticketDesignerSettings.i18n.selectImage,
                button: {
                    text: ticketDesignerSettings.i18n.useThisImage,
                },
                multiple: false,
            });

            mediaUploader.on("select", function () {
                const attachment = mediaUploader
                    .state()
                    .get("selection")
                    .first()
                    .toJSON();
                targetInput.val(attachment.url);

                // If the button itself is a preview tile, refresh the thumbnail
                if (button.hasClass("image-preview-btn") || button.hasClass("logo-preview-btn")) {
                    button.find(".logo-preview-empty").remove();
                    let $img = button.find(".logo-preview-img");
                    if (!$img.length) {
                        $img = $('<img class="logo-preview-img" alt="">').prependTo(button);
                    }
                    $img.attr("src", attachment.url);
                }

                // For content row images, auto-populate width/height if they're 0
                if (
                    isContentRowImage &&
                    attachment.width &&
                    attachment.height
                ) {
                    const row = button.closest(".content-row-item");
                    const widthInput = row.find(
                        'input[name="content_rows[' + rowIndex + '][width]"]'
                    );
                    const heightInput = row.find(
                        'input[name="content_rows[' + rowIndex + '][height]"]'
                    );

                    // Set dimensions if current values are 0 or empty
                    if (
                        !widthInput.val() ||
                        parseFloat(widthInput.val()) === 0
                    ) {
                        widthInput.val(attachment.width);
                    }
                    if (
                        !heightInput.val() ||
                        parseFloat(heightInput.val()) === 0
                    ) {
                        heightInput.val(attachment.height);
                    }
                }

                triggerPreviewUpdate();
            });

            mediaUploader.open();
        });

        // Logo uploader — click either the preview tile OR any legacy
        // .upload-logo-button; updates URL input + preview thumbnail.
        $(document).on("click", ".upload-logo-button", function (e) {
            e.preventDefault();

            const button = $(this);
            const $item = button.closest(".logo-item");
            const targetInput = $item.length
                ? $item.find(".logo-image-url").first()
                : button.siblings(".logo-image-url");

            const mediaUploader = wp.media({
                title: ticketDesignerSettings.i18n.selectLogo,
                button: {
                    text: ticketDesignerSettings.i18n.useThisImage,
                },
                multiple: false,
            });

            mediaUploader.on("select", function () {
                const attachment = mediaUploader
                    .state()
                    .get("selection")
                    .first()
                    .toJSON();
                const ext = attachment.url.split('.').pop().toLowerCase();
                if (!['png', 'jpg', 'jpeg', 'gif'].includes(ext)) {
                    alert(ticketDesignerSettings.i18n.unsupportedFormat || 'Unsupported image format. Please use PNG, JPG or GIF. WebP is not supported for PDF tickets.');
                    return;
                }
                targetInput.val(attachment.url);
                // Update preview thumbnail inside the logo tile
                if ($item.length) {
                    const $tile = $item.find(".logo-preview-btn");
                    $tile.find(".logo-preview-empty").remove();
                    let $img = $tile.find(".logo-preview-img");
                    if (!$img.length) {
                        $img = $('<img class="logo-preview-img" alt="">').prependTo($tile);
                    }
                    $img.attr("src", attachment.url);
                }
                triggerPreviewUpdate();
            });

            mediaUploader.open();
        });

        // Sync preview thumb when the URL input is edited manually
        // (logo repeater items + generic image content rows)
        $(document).on("input change", ".logo-image-url, .content-row-image-url", function () {
            const $input = $(this);
            const url = $input.val();
            let $tile;
            if ($input.hasClass("logo-image-url")) {
                $tile = $input.closest(".logo-item").find(".logo-preview-btn");
            } else {
                $tile = $input.closest(".image-picker-row").find(".image-preview-btn");
            }
            if (!$tile || !$tile.length) return;
            if (url) {
                let $img = $tile.find(".logo-preview-img");
                if (!$img.length) {
                    $tile.find(".logo-preview-empty").remove();
                    $img = $('<img class="logo-preview-img" alt="">').prependTo($tile);
                }
                $img.attr("src", url);
            } else {
                $tile.find(".logo-preview-img").remove();
                if (!$tile.find(".logo-preview-empty").length) {
                    $tile.append('<span class="logo-preview-empty dashicons dashicons-format-image"></span>');
                }
            }
        });
    }

    /**
     * Sortable for logos inside a logo_row block.
     */
    function initializeLogoSortable($container) {
        if (!$container || !$container.length) return;
        if ($container.hasClass("ui-sortable")) return;
        $container.sortable({
            handle: ".logo-drag-handle",
            axis: "y",
            cursor: "grabbing",
            opacity: 0.85,
            placeholder: "logo-sortable-placeholder",
            update: function () {
                triggerPreviewUpdate();
            },
        });
    }
    function initializeAllLogoSortables() {
        $(".logo-items-container").each(function () {
            initializeLogoSortable($(this));
        });
    }

    /**
     * Initialize content row repeater
     */
    function initializeContentRowRepeater() {
        // Add new row
        $("#add-content-row").on("click", function () {
            const template = $("#content-row-template").html();
            const html = template.replace(/\{\{INDEX\}\}/g, contentRowIndex);
            const $newRow = $(html);
            $("#content-rows-repeater").append($newRow);

            // Initialize disabled state for the new row (default type is 'text')
            $newRow
                .find(".content-row-fields")
                .find("input, textarea, select")
                .prop("disabled", true);
            $newRow
                .find(".content-row-text")
                .find("input, textarea, select")
                .prop("disabled", false);

            // Initialize TinyMCE for the new editor
            const editorId = "content_row_text_" + contentRowIndex;
            if (typeof tinymce !== "undefined") {
                tinymce.init({
                    selector: "#" + editorId,
                    toolbar1: "bold,italic,|,undo,redo",
                    toolbar2: "",
                    menubar: false,
                    setup: function (editor) {
                        editor.on("change", function () {
                            triggerPreviewUpdate();
                        });
                    },
                });
            }

            contentRowIndex++;
        });

        // Duplicate row
        //
        // Built from the same template as "Add block" and then filled with the
        // source values. The DOM of the source row is deliberately NOT cloned: a
        // text block holds a TinyMCE instance which replaces the textarea with an
        // iframe, so a clone would be a broken editor.
        $(document).on("click", ".duplicate-content-row", function () {
            const $source = $(this).closest(".content-row-item");
            const sourceIndex = $source.data("index");
            const newIndex = contentRowIndex;

            const template = $("#content-row-template").html();
            const $newRow = $(template.replace(/\{\{INDEX\}\}/g, newIndex));
            $source.after($newRow);

            // Type first: the change handler enables the matching field group.
            const type = $source.find(".content-row-type").val();
            $newRow.find(".content-row-type").val(type).trigger("change");

            // Copy every value across, matched by the field name without its index.
            const sourcePrefix = "content_rows[" + sourceIndex + "]";
            const newPrefix = "content_rows[" + newIndex + "]";
            $source.find("input, select, textarea").each(function () {
                const name = $(this).attr("name");
                if (!name || name.indexOf(sourcePrefix) !== 0) {
                    return;
                }
                const targetName = newPrefix + name.slice(sourcePrefix.length);
                const $target = $newRow.find('[name="' + targetName + '"]');
                if (!$target.length) {
                    return;
                }
                if ($(this).is(":checkbox") || $(this).is(":radio")) {
                    $target.prop("checked", $(this).prop("checked"));
                } else {
                    $target.val($(this).val());
                }
            });

            // Logo rows keep their images in repeated [logos][] entries that are
            // added at runtime, so those nodes are copied with the index rewritten.
            const $sourceLogos = $source.find(".logo-items-container").first();
            if ($sourceLogos.length) {
                const rewritten = $sourceLogos
                    .html()
                    .split(sourcePrefix)
                    .join(newPrefix);
                $newRow.find(".logo-items-container").first().html(rewritten);
            }

            // The editor content comes from TinyMCE, the textarea can be stale.
            if (typeof tinymce !== "undefined") {
                const sourceEditor = tinymce.get("content_row_text_" + sourceIndex);
                if (sourceEditor) {
                    const newTextarea = $newRow.find(
                        '[name="' + newPrefix + '[text]"]'
                    );
                    newTextarea.val(sourceEditor.getContent());
                }

                tinymce.init({
                    selector: "#content_row_text_" + newIndex,
                    toolbar1: "bold,italic,|,undo,redo",
                    toolbar2: "",
                    menubar: false,
                    setup: function (editor) {
                        editor.on("change", function () {
                            triggerPreviewUpdate();
                        });
                    },
                });
            }

            contentRowIndex++;
            triggerPreviewUpdate();
        });

        // Remove row
        $(document).on("click", ".remove-content-row", function () {
            $(this).closest(".content-row-item").remove();
            triggerPreviewUpdate();
        });

        // Handle row type change
        $(document).on("change", ".content-row-type", function () {
            const row = $(this).closest(".content-row-item");
            const type = $(this).val();

            // Disable inputs in hidden sections to prevent them from being submitted
            row.find(".content-row-fields")
                .hide()
                .find("input, textarea, select")
                .prop("disabled", true);
            row.find(".content-row-" + type)
                .show()
                .find("input, textarea, select")
                .prop("disabled", false);

            // For image type, check alignment and disable absolute position fields if needed
            if (type === "image") {
                const alignment = row.find(".image-alignment-select").val();
                if (alignment !== "absolute") {
                    row.find(".absolute-position-fields")
                        .find("input, textarea, select")
                        .prop("disabled", true);
                }
            }

            triggerPreviewUpdate();
        });

        // Handle image alignment change
        $(document).on("change", ".image-alignment-select", function () {
            const row = $(this).closest(".content-row-item");
            const alignment = $(this).val();

            if (alignment === "absolute") {
                row.find(".absolute-position-fields")
                    .show()
                    .find("input, textarea, select")
                    .prop("disabled", false);
            } else {
                row.find(".absolute-position-fields")
                    .hide()
                    .find("input, textarea, select")
                    .prop("disabled", true);
            }

            triggerPreviewUpdate();
        });

        // Add logo to logo row
        $(document).on("click", ".add-logo-button", function () {
            const button = $(this);
            const rowIndex = button.data("row-index");
            const container = button.siblings(".logo-items-container");
            const logoIndex = container.find(".logo-item").length;

            const logoHtml = `
                <div class="logo-item" data-logo-index="${logoIndex}">
                    <span class="logo-drag-handle dashicons dashicons-menu" title="Drag to reorder"></span>
                    <button type="button" class="logo-preview-btn upload-logo-button"
                            data-row-index="${rowIndex}"
                            data-logo-index="${logoIndex}">
                        <span class="logo-preview-empty dashicons dashicons-format-image"></span>
                    </button>
                    <div class="logo-url-field">
                        <input type="text"
                               name="content_rows[${rowIndex}][logos][][image_url]"
                               value=""
                               class="logo-image-url settings-input"
                               placeholder="${ticketDesignerSettings.i18n.imageUrl}">
                    </div>
                    <button type="button" class="button remove-logo-button" title="Remove">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
            `;

            container.append(logoHtml);
            initializeLogoSortable(container);
            triggerPreviewUpdate();
        });

        // Remove logo from logo row
        $(document).on("click", ".remove-logo-button", function () {
            const logoItem = $(this).closest(".logo-item");
            const container = logoItem.closest(".logo-items-container");

            // Keep at least one logo item
            if (container.find(".logo-item").length > 1) {
                logoItem.remove();
                triggerPreviewUpdate();
            }
        });
    }

    /**
     * Initialize live preview
     */
    function initializeLivePreview() {
        // Trigger preview on input change
        $(document).on("input change", ".settings-input", function () {
            triggerPreviewUpdate();
        });

        // Manual refresh button
        $("#refresh-preview").on("click", function () {
            updatePreview();
        });

        // Initial preview load
        updatePreview();
    }

    /**
     * Trigger preview update with debounce
     */
    function triggerPreviewUpdate() {
        clearTimeout(previewDebounceTimer);
        previewDebounceTimer = setTimeout(function () {
            updatePreview();
        }, 500);
    }

    /**
     * Update the preview iframe
     */
    function updatePreview() {
        const settings = collectSettings();

        $("#preview-loading").show();
        $("#ticket-preview-frame").removeClass("loaded");

        // Get the current ticket language and product from hidden fields
        const ticketLang = $('input[name="ticket_lang"]').val() || "";
        const ticketProductId = $('input[name="ticket_product_id"]').val() || "0";

        $.ajax({
            url: ticketDesignerSettings.ajax_url,
            type: "POST",
            data: {
                action: "preview_ticket_design",
                nonce: ticketDesignerSettings.nonce,
                settings: settings,
                ticket_lang: ticketLang,
                ticket_product_id: ticketProductId,
            },
            success: function (response) {
                if (response.success) {
                    const iframe = $("#ticket-preview-frame")[0];
                    iframe.src = response.data.pdf_url + "&t=" + Date.now();

                    iframe.onload = function () {
                        $("#preview-loading").hide();
                        $("#ticket-preview-frame").addClass("loaded");
                    };
                } else {
                    console.error("Preview error:", response.data.message);
                    $("#preview-loading").hide();
                    alert(ticketDesignerSettings.i18n.errorPreview + " " + response.data.message);
                }
            },
            error: function (xhr, status, error) {
                console.error("AJAX error:", error);
                $("#preview-loading").hide();
                alert(ticketDesignerSettings.i18n.errorPreviewRetry);
            },
        });
    }

    /**
     * Collect all settings from form inputs
     */
    function collectSettings() {
        // Temporarily enable all disabled inputs to collect their values
        // This includes inputs in hidden content-row-fields sections
        const disabledInputs = $(
            ".content-row-fields input:disabled, .content-row-fields textarea:disabled, .content-row-fields select:disabled"
        );

        disabledInputs.prop("disabled", false);

        const settings = {
            header_image: $("#header_image").val(),
            hidden_default_fields: collectHiddenFields(),
            left_column: {
                x: parseInt($("#left_column_x").val()) || 265,
                y: parseInt($("#left_column_y").val()) || 185,
                width: parseInt($("#left_column_width").val()) || 320,
                height: parseInt($("#left_column_height").val()) || 300,
                font_size: parseInt($("#left_column_font_size").val()) || 12,
                color: $("#left_column_color").val() || "#000000",
                label_color: $("#left_column_label_color").val() || "",
                vertical_center: $("#left_column_vertical_center").is(
                    ":checked"
                ),
                label_position: $("#left_column_label_position").val() || "inline",
                word_wrap: $("#left_column_word_wrap").val() || "break",
                line_height: parseInt($("#left_column_line_height").val()) || 35,
            },
            right_column: {
                x: parseInt($("#right_column_x").val()) || 703,
                y: parseInt($("#right_column_y").val()) || 185,
                width: parseInt($("#right_column_width").val()) || 320,
                height: parseInt($("#right_column_height").val()) || 300,
                font_size: parseInt($("#right_column_font_size").val()) || 12,
                color: $("#right_column_color").val() || "#000000",
                label_color: $("#right_column_label_color").val() || "",
                vertical_center: $("#right_column_vertical_center").is(
                    ":checked"
                ),
                label_position: $("#right_column_label_position").val() || "inline",
                word_wrap: $("#right_column_word_wrap").val() || "break",
                line_height: parseInt($("#right_column_line_height").val()) || 35,
            },
            qr_code: {
                x: parseInt($("#qr_code_x").val()) || 1238,
                y: parseInt($("#qr_code_y").val()) || 190,
                size: parseInt($("#qr_code_size").val()) || 300,
            },
            ticket_frame: {
                enabled: $('input[name="ticket_frame[enabled]"]').is(":checked") ? 1 : 0,
                height: parseInt($('input[name="ticket_frame[height]"]').val()) || 438,
                y: parseInt($('input[name="ticket_frame[y]"]').val()) || 150,
                logo_gap: Math.max(0, parseInt($('input[name="ticket_frame[logo_gap]"]').val()) || 0),
                border_radius: parseInt($('input[name="ticket_frame[border_radius]"]').val()) || 26,
                bg_type: $('select[name="ticket_frame[bg_type]"]').val() || "gradient",
                bg_color: $('input[name="ticket_frame[bg_color]"]').val() || "#C479E6",
                bg_color_2: $('input[name="ticket_frame[bg_color_2]"]').val() || "#54C8EA",
                gradient_direction: $('select[name="ticket_frame[gradient_direction]"]').val() || "horizontal",
                bg_image: $('input[name="ticket_frame[bg_image]"]').val() || "",
                stub_color: $('input[name="ticket_frame[stub_color]"]').val() || "#9C1C90",
                stub_width: parseInt($('input[name="ticket_frame[stub_width]"]').val()) || 107,
                perf_enabled: 1,
                perf_x: parseInt($('input[name="ticket_frame[perf_x]"]').val()) || 1013,
                perf_color: $('input[name="ticket_frame[perf_color]"]').val() || "#0D1A26",
                right_text: $('textarea[name="ticket_frame[right_text]"]').val() || "",
                right_text_color: $('input[name="ticket_frame[right_text_color]"]').val() || "#FFFFFF",
                right_text_size: parseInt($('input[name="ticket_frame[right_text_size]"]').val()) || 9,
                cut_line_enabled: 1,
                cut_line_color: $('input[name="ticket_frame[cut_line_color]"]').val() || "#111111",
                cut_line_gap: parseInt($('input[name="ticket_frame[cut_line_gap]"]').val()) || 60,
            },
            content_rows: [],
            ticket_field_order_first: collectFieldOrder("ticket_field_order_first"),
            ticket_field_order_second: collectFieldOrder("ticket_field_order_second"),
            auto_field_layout: $("#auto_field_layout").is(":checked") ? 1 : 0,
            footer_text: (function () {
                var text = "";
                var editor = typeof tinymce !== "undefined"
                    ? tinymce.get("footer_text_content")
                    : null;
                if (editor && editor.initialized) {
                    text = editor.getContent();
                }
                if (!text) {
                    text = $('textarea[name="footer_text[text]"]').val() || "";
                }
                return {
                    enabled: $('input[name="footer_text[enabled]"]').is(":checked") ? 1 : 0,
                    text: text,
                    font_size: parseInt($('input[name="footer_text[font_size]"]').val()) || 12,
                    color: $('input[name="footer_text[color]"]').val() || "#000000",
                    x: parseInt($('input[name="footer_text[x]"]').val()) || 97,
                    width: parseInt($('input[name="footer_text[width]"]').val()) || 0,
                };
            })(),
        };

        // Collect content rows
        $(".content-row-item").each(function () {
            const row = $(this);
            const index = row.data("index");
            const type = row.find(".content-row-type").val();

            // Every row-type section (text/image/logo_row/pill/qr_code)
            // ships its own padding_top/padding_bottom inputs, so an
            // unscoped `input[name$="[padding_top]"]` grabs the first
            // section's copy — always the hidden text section on non-text
            // rows — silently ignoring the value the admin edited. Scope
            // the lookup to the ACTIVE type's section instead.
            var $activeSection = row.find('.content-row-' + type);
            const rowData = {
                type: type,
                padding_top: (function () {
                    var $el = $activeSection.find('input[name$="[padding_top]"]').first();
                    var v = $el.val();
                    return v === '' || v === undefined ? 5 : parseInt(v, 10);
                })(),
                padding_bottom: (function () {
                    var $el = $activeSection.find('input[name$="[padding_bottom]"]').first();
                    var v = $el.val();
                    return v === '' || v === undefined ? 5 : parseInt(v, 10);
                })(),
            };

            if (type === "text") {
                const textSection = row.find(".content-row-text");

                // Get text from TinyMCE editor if available
                const editorId = "content_row_text_" + index;
                let textContent = "";
                const editor = typeof tinymce !== "undefined" ? tinymce.get(editorId) : null;
                if (editor && editor.initialized) {
                    textContent = editor.getContent();
                }
                // Fallback to textarea if TinyMCE is not ready yet
                if (!textContent) {
                    textContent =
                        textSection
                            .find(
                                'textarea[name="content_rows[' +
                                    index +
                                    '][text]"]'
                            )
                            .val() || "";
                }

                rowData.text = textContent;
                rowData.font_size =
                    parseInt(
                        textSection
                            .find(
                                'input[name="content_rows[' +
                                    index +
                                    '][font_size]"]'
                            )
                            .val()
                    ) || 8;
                rowData.color =
                    textSection
                        .find(
                            'input[name="content_rows[' + index + '][color]"]'
                        )
                        .val() || "#000000";
                rowData.width =
                    parseFloat(
                        textSection
                            .find(
                                'input[name="content_rows[' +
                                    index +
                                    '][width]"]'
                            )
                            .val()
                    ) || 0;
                rowData.x =
                    parseFloat(
                        textSection
                            .find(
                                'input[name="content_rows[' + index + '][x]"]'
                            )
                            .val()
                    ) || 0;
                rowData.y =
                    parseFloat(
                        textSection
                            .find(
                                'input[name="content_rows[' + index + '][y]"]'
                            )
                            .val()
                    ) || 0;
            } else if (type === "image") {
                const imageSection = row.find(".content-row-image");

                rowData.image_url =
                    imageSection
                        .find(
                            'input[name="content_rows[' +
                                index +
                                '][image_url]"]'
                        )
                        .val() || "";
                rowData.alignment =
                    imageSection
                        .find(
                            'select[name="content_rows[' +
                                index +
                                '][alignment]"]'
                        )
                        .val() || "centered";
                rowData.width =
                    parseFloat(
                        imageSection
                            .find(
                                'input[name="content_rows[' +
                                    index +
                                    '][width]"]'
                            )
                            .val()
                    ) || 0;
                rowData.height =
                    parseFloat(
                        imageSection
                            .find(
                                'input[name="content_rows[' +
                                    index +
                                    '][height]"]'
                            )
                            .val()
                    ) || 0;

                // Y position is supported for both centered and absolute alignment
                rowData.y =
                    parseFloat(
                        imageSection
                            .find(
                                'input[name="content_rows[' + index + '][y]"]'
                            )
                            .val()
                    ) || 0;

                // Only include x if alignment is absolute
                if (rowData.alignment === "absolute") {
                    rowData.x =
                        parseFloat(
                            imageSection
                                .find(
                                    'input[name="content_rows[' +
                                        index +
                                        '][x]"]'
                                )
                                .val()
                        ) || 0;
                }
            } else if (type === "logo_row") {
                const logoSection = row.find(".content-row-logo_row");

                // Collect individual logos (only image URLs, X positions will be calculated)
                const logos = [];
                logoSection.find(".logo-item").each(function () {
                    const logoItem = $(this);
                    const logoImageUrl = logoItem.find(".logo-image-url").val();

                    if (logoImageUrl) {
                        logos.push({
                            image_url: logoImageUrl,
                        });
                    }
                });

                rowData.logos = logos;
                rowData.y =
                    parseFloat(
                        logoSection
                            .find(
                                'input[name="content_rows[' + index + '][y]"]'
                            )
                            .val()
                    ) || 0;
                rowData.width =
                    parseFloat(
                        logoSection
                            .find(
                                'input[name="content_rows[' +
                                    index +
                                    '][width]"]'
                            )
                            .val()
                    ) || 0;
                rowData.height =
                    parseFloat(
                        logoSection
                            .find(
                                'input[name="content_rows[' +
                                    index +
                                    '][height]"]'
                            )
                            .val()
                    ) || 0;

                // Collect spacing parameter (empty value means auto-calculate)
                const spacingValue = logoSection
                    .find('input[name="content_rows[' + index + '][spacing]"]')
                    .val();
                rowData.spacing = spacingValue
                    ? parseFloat(spacingValue)
                    : null;
            } else if (type === "qr_code") {
                const qrSection = row.find(".content-row-qr_code");

                rowData.qr_content =
                    qrSection
                        .find(
                            'input[name="content_rows[' +
                                index +
                                '][qr_content]"]'
                        )
                        .val() || "";
                rowData.x =
                    parseFloat(
                        qrSection
                            .find(
                                'input[name="content_rows[' + index + '][x]"]'
                            )
                            .val()
                    ) || 0;
                rowData.y =
                    parseFloat(
                        qrSection
                            .find(
                                'input[name="content_rows[' + index + '][y]"]'
                            )
                            .val()
                    ) || 0;
                rowData.size =
                    parseFloat(
                        qrSection
                            .find(
                                'input[name="content_rows[' +
                                    index +
                                    '][size]"]'
                            )
                            .val()
                    ) || 270;

                // QR frame (border) options
                var qrField = function (key) {
                    return qrSection.find('input[name="content_rows[' + index + '][' + key + ']"], select[name="content_rows[' + index + '][' + key + ']"]');
                };
                rowData.border_enabled = qrField("border_enabled").is(":checked") ? 1 : 0;
                rowData.border_color   = qrField("border_color").val() || "#2B5C63";
                rowData.border_width   = parseFloat(qrField("border_width").val()) || 0;
                rowData.border_radius  = parseFloat(qrField("border_radius").val()) || 0;
                rowData.padding        = parseFloat(qrField("padding").val()) || 0;

                // QR title pill options
                rowData.title_text      = qrField("title_text").val() || "";
                rowData.title_bg_color  = qrField("title_bg_color").val() || "#9C27B0";
                rowData.title_color     = qrField("title_color").val() || "#FFFFFF";
                rowData.title_font_size = parseInt(qrField("title_font_size").val()) || 16;
                rowData.title_height    = parseFloat(qrField("title_height").val()) || 0;

                // Inline-with-next-block toggle
                rowData.inline_next = qrField("inline_next").is(":checked") ? 1 : 0;
                rowData.padding_left = Math.max(0, parseInt(qrField("padding_left").val(), 10) || 0);
                rowData.padding_right = Math.max(0, parseInt(qrField("padding_right").val(), 10) || 0);
            } else if (type === "pill") {
                var pillSection = row.find(".content-row-pill");
                var pillField = function (key) {
                    return pillSection.find(
                        'input[name="content_rows[' + index + '][' + key + ']"], select[name="content_rows[' + index + '][' + key + ']"]'
                    );
                };
                rowData.text          = pillField("text").val() || "";
                rowData.bg_color      = pillField("bg_color").val() || "#9C27B0";
                rowData.color         = pillField("color").val() || "#FFFFFF";
                rowData.font_size     = parseInt(pillField("font_size").val()) || 18;
                rowData.width         = parseFloat(pillField("width").val()) || 400;
                rowData.height        = parseFloat(pillField("height").val()) || 60;
                rowData.border_radius = parseFloat(pillField("border_radius").val()) || 30;
                rowData.alignment     = pillField("alignment").val() || "centered";
                rowData.y             = parseFloat(pillField("y").val()) || 0;
                if (rowData.alignment === "absolute") {
                    rowData.x = parseFloat(pillField("x").val()) || 0;
                }
            }

            settings.content_rows.push(rowData);
        });

        // Restore disabled state for previously disabled inputs
        disabledInputs.prop("disabled", true);

        return settings;
    }

    /**
     * Initialize sortable drag and drop for content rows
     */
    function initializeSortable() {
        $("#content-rows-repeater").sortable({
            handle: ".content-row-header",
            axis: "y",
            cursor: "move",
            opacity: 0.7,
            placeholder: "sortable-placeholder",
            start: function (e, ui) {
                ui.placeholder.height(ui.item.height());
            },
            update: function () {
                triggerPreviewUpdate();
            },
        });
    }

    /**
     * When "Auto-distribute filled fields evenly between columns" is checked,
     * the manual drag/reorder UI is redundant — hide it. When unchecked, show
     * it so admin can arrange fields explicitly.
     */
    function initializeAutoLayoutToggle() {
        var $toggle = $('#auto_field_layout');
        var $columns = $('.ticket-field-columns');
        if (!$toggle.length || !$columns.length) return;
        function sync() {
            if ($toggle.is(':checked')) {
                $columns.hide();
            } else {
                $columns.show();
            }
        }
        $toggle.on('change', function () {
            sync();
            triggerPreviewUpdate();
        });
        sync();
    }

    function initializeTicketFieldOrderSortable() {
        $(".ticket-field-order-list").sortable({
            connectWith: ".ticket-field-order-list",
            cursor: "move",
            opacity: 0.7,
            update: function (e, ui) {
                // Update hidden input name to match the new list's data-field
                var $list = ui.item.parent(".ticket-field-order-list");
                var fieldName = $list.data("field");
                ui.item.find(".ticket-field-key-input").attr("name", fieldName + "[]");
                triggerPreviewUpdate();
            },
        });

        // Trigger preview when visibility checkboxes change
        $(document).on("change", ".ticket-field-visible", function () {
            triggerPreviewUpdate();
        });
    }

    /**
     * Set a field value based on its type. Handles color-pickers (iris),
     * checkboxes, TinyMCE editors, and regular inputs/selects/textareas.
     */
    function setFieldValue($el, value) {
        if (!$el.length) return;
        var el = $el[0];
        var tag = (el.tagName || '').toLowerCase();
        if (el.type === 'checkbox') {
            $el.prop('checked', !!value);
            return;
        }
        if ($el.hasClass('color-picker') && typeof $el.iris === 'function') {
            $el.iris('color', value || '');
            return;
        }
        if (tag === 'textarea' && typeof tinymce !== 'undefined') {
            var ed = tinymce.get(el.id);
            if (ed && ed.initialized) {
                ed.setContent(value || '');
            }
        }
        $el.val(value == null ? '' : value);
        $el.trigger('change');
    }

    /**
     * Per-block Reset — clears all "Customize" fields in a single content row
     * back to sensible Auto defaults (padding = 5, everything else = 0/empty).
     * Fires on the summary's Reset button; we stop propagation so the click
     * doesn't toggle the <details> open/closed state.
     */
    function initializeBlockReset() {
        $(document).on('click', '.block-reset', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn = $(this);
            var type = $btn.data('row-type');
            var $row = $btn.closest('.content-row-item');
            if (!$row.length) return;

            // Defaults per row type — mirror parseContentRows / PDF fallbacks
            var typeDefaults = {
                text:     { padding_top: 5, padding_bottom: 5, font_size: 12, color: '#000000', width: 0, x: 0, y: 0 },
                image:    { padding_top: 5, padding_bottom: 5, alignment: 'centered', x: 0, y: 0, width: 0, height: 0 },
                logo_row: { padding_top: 5, padding_bottom: 5, y: 0, width: 0, height: 0, spacing: '' },
                qr_code:  { padding_top: 5, padding_bottom: 5, x: 0, y: 0, size: 270,
                            border_enabled: 0, border_color: '#2B5C63', border_width: 4, border_radius: 20, padding: 20,
                            title_text: '', title_bg_color: '#9C27B0', title_color: '#FFFFFF',
                            title_font_size: 11, title_height: 60, padding_left: 0, padding_right: 0 },
                pill:     { padding_top: 5, padding_bottom: 5, bg_color: '#9C27B0', color: '#FFFFFF',
                            font_size: 18, width: 400, height: 60, border_radius: 30,
                            alignment: 'centered', x: 0, y: 0 },
            };
            var d = typeDefaults[type] || {};
            var idx = $row.data('index');

            Object.keys(d).forEach(function (key) {
                var $el = $row.find('[name="content_rows[' + idx + '][' + key + ']"]');
                if (!$el.length) return;
                setFieldValue($el, d[key]);
            });

            // Re-run any alignment-dependent visibility togglers
            $row.find('.image-alignment-select, .pill-alignment-select').trigger('change');
            triggerPreviewUpdate();
        });
    }

    /**
     * "Reset section" — restore all inputs in a named section to the defaults
     * shipped by TicketDesignerSettings::getDefaultSettings() (localized as
     * ticketDesignerSettings.defaults). Trigger a preview refresh afterwards.
     */
    function initializeSectionReset() {
        $(document).on('click', '.section-reset', function () {
            var section = $(this).data('section');
            var defaults = (ticketDesignerSettings.defaults || {});
            if (!confirm('Reset this section to defaults?')) return;

            if (section === 'header_logo') {
                setFieldValue($('#header_image'), '');
                $('#header_image').closest('.image-picker-row')
                    .find('.image-preview-btn .logo-preview-img').remove();
                var $tile = $('#header_image').closest('.image-picker-row').find('.image-preview-btn');
                if (!$tile.find('.logo-preview-empty').length) {
                    $tile.append('<span class="logo-preview-empty dashicons dashicons-format-image"></span>');
                }
            } else if (section === 'ticket_frame') {
                var tf = defaults.ticket_frame || {};
                Object.keys(tf).forEach(function (k) {
                    var $el = $('[name="ticket_frame[' + k + ']"]');
                    setFieldValue($el, tf[k]);
                });
                // Media button target sync (bg_image)
                $('#ticket_frame_bg_image').val(tf.bg_image || '').trigger('change');
                // Re-run the frame's own bg-type / enable toggler
                $('#ticket_frame_enabled').trigger('change');
                $('select[name="ticket_frame[bg_type]"]').trigger('change');
            } else if (section === 'left_column' || section === 'right_column') {
                var cfg = defaults[section] || {};
                var prefix = section + '_';
                Object.keys(cfg).forEach(function (k) {
                    setFieldValue($('#' + prefix + k), cfg[k]);
                });
            } else if (section === 'qr_code') {
                var qr = defaults.qr_code || {};
                Object.keys(qr).forEach(function (k) {
                    setFieldValue($('#qr_code_' + k), qr[k]);
                });
            } else if (section === 'content_rows') {
                // Wipe all rows (defaults to none)
                $('.content-row-item').each(function () {
                    var idx = $(this).data('index');
                    var edId = 'content_row_text_' + idx;
                    if (typeof tinymce !== 'undefined' && tinymce.get(edId)) {
                        tinymce.get(edId).remove();
                    }
                });
                $('#content-rows-repeater').empty();
            } else if (section === 'footer_text') {
                var ft = defaults.footer_text || {};
                Object.keys(ft).forEach(function (k) {
                    var $el = $('[name="footer_text[' + k + ']"]');
                    setFieldValue($el, ft[k]);
                });
                // Sync TinyMCE editor
                if (typeof tinymce !== 'undefined') {
                    var edFT = tinymce.get('footer_text_content');
                    if (edFT && edFT.initialized) {
                        edFT.setContent(ft.text || '');
                    }
                }
                // Re-run the footer's own enable toggler
                $('#footer_text_enabled').trigger('change');
            }

            triggerPreviewUpdate();
        });
    }

    /**
     * "Reset to auto" — collect ALL field <li>s across both column lists in
     * their natural DOM order (labels come pre-sorted from PHP), split them
     * evenly by count, and re-parent them so the layout matches what a fresh
     * install would show. Preserves each item's visibility checkbox state.
     */
    function initializeResetFieldLayout() {
        $(document).on("click", "#reset-field-layout", function () {
            var $first = $('.ticket-field-order-list[data-field="ticket_field_order_first"]');
            var $second = $('.ticket-field-order-list[data-field="ticket_field_order_second"]');
            if (!$first.length || !$second.length) return;

            // Grab all items in current DOM order (first column first, then second)
            var $allItems = $first.find("> li").add($second.find("> li"));
            if (!$allItems.length) return;

            var total = $allItems.length;
            var splitAt = Math.ceil(total / 2);

            $first.empty();
            $second.empty();
            $allItems.each(function (i) {
                var $item = $(this);
                var $keyInput = $item.find(".ticket-field-key-input");
                if (i < splitAt) {
                    $keyInput.attr("name", "ticket_field_order_first[]");
                    $first.append($item);
                } else {
                    $keyInput.attr("name", "ticket_field_order_second[]");
                    $second.append($item);
                }
            });

            triggerPreviewUpdate();
        });
    }

    function collectFieldOrder(fieldName) {
        return $('.ticket-field-order-list[data-field="' + fieldName + '"] li')
            .map(function () {
                // Only include if the item's visibility checkbox is checked (so hidden items are excluded from order)
                var $item = $(this);
                var key = $item.find(".ticket-field-key-input").val();
                return key;
            })
            .get();
    }

    function collectHiddenFields() {
        return $(".ticket-field-order-list .ticket-field-visible:not(:checked)")
            .map(function () { return $(this).val(); })
            .get();
    }

    /**
     * Initialize content row states - disable inputs in hidden sections
     */
    function initializeContentRowStates() {
        $(".content-row-item").each(function () {
            const row = $(this);
            const type = row.find(".content-row-type").val();

            // Disable all inputs first
            row.find(".content-row-fields")
                .find("input, textarea, select")
                .prop("disabled", true);
            // Enable only the active type's inputs
            row.find(".content-row-" + type)
                .find("input, textarea, select")
                .prop("disabled", false);

            // For image type, handle absolute positioning fields
            if (type === "image") {
                const alignment = row.find(".image-alignment-select").val();
                if (alignment !== "absolute") {
                    // Disable absolute position fields if not in absolute mode
                    row.find(".absolute-position-fields")
                        .find("input, textarea, select")
                        .prop("disabled", true);
                }
            }
        });
    }

    /**
     * Export settings to JSON file
     */
    function exportSettings() {
        const settings = collectSettings();
        const dataStr = JSON.stringify(settings, null, 2);
        const dataBlob = new Blob([dataStr], { type: "application/json" });
        const url = URL.createObjectURL(dataBlob);
        const link = document.createElement("a");
        link.href = url;
        link.download = "ticket-designer-settings-" + Date.now() + ".json";
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    /**
     * Import settings from JSON file
     */
    function importSettings(event) {
        const file = event.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function (e) {
            try {
                const settings = JSON.parse(e.target.result);
                applySettings(settings);
                alert(ticketDesignerSettings.i18n.importSuccess);
            } catch (error) {
                console.error("Import error:", error);
                console.error("Error stack:", error.stack);
                console.error("Error message:", error.message);
                alert(ticketDesignerSettings.i18n.errorImport + " " + error.message);
            }
        };
        reader.readAsText(file);

        // Reset file input
        event.target.value = "";
    }

    /**
     * Sync the image-preview tile associated with a URL input to reflect
     * its current value. Removes the placeholder icon and adds/updates the
     * <img> when a URL is set, or restores the placeholder when cleared.
     */
    function syncImagePreviewTile($input) {
        if (!$input || !$input.length) return;
        var $tile;
        if ($input.hasClass('logo-image-url')) {
            $tile = $input.closest('.logo-item').find('.logo-preview-btn');
        } else {
            $tile = $input.closest('.image-picker-row').find('.image-preview-btn');
        }
        if (!$tile.length) return;
        var url = $input.val();
        if (url) {
            $tile.find('.logo-preview-empty').remove();
            var $img = $tile.find('.logo-preview-img');
            if (!$img.length) {
                $img = $('<img class="logo-preview-img" alt="">').prependTo($tile);
            }
            $img.attr('src', url);
        } else {
            $tile.find('.logo-preview-img').remove();
            if (!$tile.find('.logo-preview-empty').length) {
                $tile.append('<span class="logo-preview-empty dashicons dashicons-format-image"></span>');
            }
        }
    }

    /**
     * Apply a flat key→value settings group (ticket_frame, footer_text) to
     * every matching input identified by name="prefix[key]". Uses
     * setFieldValue so checkboxes, color-pickers, textareas, and regular
     * inputs are all handled correctly.
     */
    function applyKeyValueGroup(group, namePrefix) {
        if (!group || typeof group !== 'object') return;
        Object.keys(group).forEach(function (key) {
            var $el = $('[name="' + namePrefix + '[' + key + ']"]');
            if ($el.length) setFieldValue($el, group[key]);
        });
    }

    /**
     * Rebuild both ticket-field-order columns to match an imported settings
     * object. Existing <li> nodes are reused (preserving labels + monospace
     * key hints); only their column and the visibility checkbox change.
     * Any keys the import doesn't mention stay where they were so labels
     * don't vanish from the UI just because they weren't in the file.
     */
    function applyTicketFieldOrder(firstOrder, secondOrder, hiddenFields) {
        var $first  = $('.ticket-field-order-list[data-field="ticket_field_order_first"]');
        var $second = $('.ticket-field-order-list[data-field="ticket_field_order_second"]');
        if (!$first.length || !$second.length) return;

        var index = {};
        $first.find('> li').add($second.find('> li')).each(function () {
            var $li = $(this);
            var key = $li.attr('data-key') || $li.find('.ticket-field-key-input').val();
            if (key) index[key] = $li;
        });

        var used = {};
        function appendKeys(keys, $list, fieldName) {
            (keys || []).forEach(function (key) {
                if (!key || used[key] || !index[key]) return;
                var $li = index[key];
                $li.find('.ticket-field-key-input').attr('name', fieldName + '[]');
                $list.append($li);
                used[key] = true;
            });
        }
        appendKeys(firstOrder, $first, 'ticket_field_order_first');
        appendKeys(secondOrder, $second, 'ticket_field_order_second');

        Object.keys(index).forEach(function (key) {
            if (used[key]) return;
            var $li = index[key];
            var $parent = $li.parent('.ticket-field-order-list');
            var col = $parent.data('field') || 'ticket_field_order_first';
            $li.find('.ticket-field-key-input').attr('name', col + '[]');
        });

        // Visibility: default all checked, then uncheck any hidden keys
        $('.ticket-field-order-list .ticket-field-visible').prop('checked', true);
        (hiddenFields || []).forEach(function (key) {
            $('.ticket-field-order-list .ticket-field-visible[value="' + key + '"]')
                .prop('checked', false);
        });
    }

    /**
     * Rebuild the content-rows repeater from an imported settings array.
     * Uses the hidden #content-row-template — same as "Add Content Row" —
     * then populates each row's inputs by name. Handles all row types
     * (text, image, logo_row, qr_code, pill) plus universal padding and
     * every row-specific option (QR border/title/inline_next, pill
     * colors/dimensions/alignment, image alignment/absolute pos, etc).
     * Returns the number of TinyMCE editors still initializing so the
     * caller can defer the preview refresh until they're ready.
     */
    function applyContentRows(rows) {
        if (!Array.isArray(rows)) rows = [];

        $('.content-row-item').each(function () {
            var idx = $(this).data('index');
            var edId = 'content_row_text_' + idx;
            if (typeof tinymce !== 'undefined' && tinymce.get(edId)) {
                tinymce.get(edId).remove();
            }
        });

        $('#content-rows-repeater').empty();
        contentRowIndex = 0;
        var pendingEditors = 0;

        rows.forEach(function (row) {
            var idx = contentRowIndex;
            var template = $('#content-row-template').html();
            var html = template.replace(/\{\{INDEX\}\}/g, idx);
            $('#content-rows-repeater').append(html);

            var $row = $('.content-row-item[data-index="' + idx + '"]');
            var type = row.type || 'text';

            $row.find('.content-row-type').val(type);
            $row.find('.content-row-fields').hide();
            $row.find('.content-row-' + type).show();

            function setRowField(key, value) {
                $row.find('[name="content_rows[' + idx + '][' + key + ']"]').val(value);
            }
            function setRowCheckbox(key, value) {
                $row.find('[name="content_rows[' + idx + '][' + key + ']"]').prop('checked', !!value);
            }
            function pick(v, fallback) {
                return (v === undefined || v === null) ? fallback : v;
            }

            // Universal per-block padding
            setRowField('padding_top',    pick(row.padding_top, 5));
            setRowField('padding_bottom', pick(row.padding_bottom, 5));

            if (type === 'text') {
                var htmlContent = textToHtml(row.text || '');
                setRowField('text', htmlContent);

                var editorId = 'content_row_text_' + idx;
                if (typeof tinymce !== 'undefined') {
                    pendingEditors++;
                    tinymce.init({
                        selector: '#' + editorId,
                        toolbar1: 'bold,italic,|,undo,redo',
                        toolbar2: '',
                        menubar: false,
                        setup: function (editor) {
                            editor.on('init', function () {
                                editor.setContent(htmlContent);
                                pendingEditors--;
                                if (pendingEditors === 0) triggerPreviewUpdate();
                            });
                            editor.on('change', function () { triggerPreviewUpdate(); });
                        },
                    });
                }
                setRowField('font_size', pick(row.font_size, 12));
                setRowField('color',     row.color || '#000000');
                setRowField('width',     row.width || 0);
                setRowField('x',         row.x || 0);
                setRowField('y',         row.y || 0);
            } else if (type === 'image') {
                var $img = $row.find('[name="content_rows[' + idx + '][image_url]"]');
                $img.val(row.image_url || '');
                syncImagePreviewTile($img);
                setRowField('alignment', row.alignment || 'centered');
                setRowField('width',     row.width || 0);
                setRowField('height',    row.height || 0);
                setRowField('y',         row.y || 0);
                if (row.alignment === 'absolute') {
                    $row.find('.absolute-position-fields').show();
                    setRowField('x', row.x || 0);
                }
            } else if (type === 'logo_row') {
                setRowField('y',       row.y || 0);
                setRowField('width',   row.width || 0);
                setRowField('height',  row.height || 0);
                setRowField('spacing', row.spacing != null ? row.spacing : '');

                if (Array.isArray(row.logos)) {
                    var $container = $row.find('.logo-items-container');
                    $container.empty();
                    row.logos.forEach(function (logo, logoIndex) {
                        var url = logo.image_url || '';
                        var preview = url
                            ? '<img class="logo-preview-img" src="' + url + '" alt="">'
                            : '<span class="logo-preview-empty dashicons dashicons-format-image"></span>';
                        var logoHtml =
                            '<div class="logo-item" data-logo-index="' + logoIndex + '">' +
                                '<span class="logo-drag-handle dashicons dashicons-menu" title="Drag to reorder"></span>' +
                                '<button type="button" class="logo-preview-btn upload-logo-button"' +
                                    ' data-row-index="' + idx + '"' +
                                    ' data-logo-index="' + logoIndex + '">' + preview + '</button>' +
                                '<div class="logo-url-field">' +
                                    '<input type="text"' +
                                    ' name="content_rows[' + idx + '][logos][][image_url]"' +
                                    ' value="' + url + '"' +
                                    ' class="logo-image-url settings-input"' +
                                    ' placeholder="' + ticketDesignerSettings.i18n.imageUrl + '">' +
                                '</div>' +
                                '<button type="button" class="button remove-logo-button" title="Remove">' +
                                    '<span class="dashicons dashicons-no-alt"></span>' +
                                '</button>' +
                            '</div>';
                        $container.append(logoHtml);
                    });
                    initializeLogoSortable($container);
                }
            } else if (type === 'qr_code') {
                setRowField('qr_content', row.qr_content || '');
                setRowField('x',          row.x || 0);
                setRowField('y',          row.y || 0);
                setRowField('size',       row.size || 270);
                setRowCheckbox('inline_next',    row.inline_next);
                setRowField('padding_left',      pick(row.padding_left, 0));
                setRowField('padding_right',     pick(row.padding_right, 0));
                setRowCheckbox('border_enabled', row.border_enabled);
                setRowField('border_color',     row.border_color || '#2B5C63');
                setRowField('border_width',     pick(row.border_width, 4));
                setRowField('border_radius',    pick(row.border_radius, 20));
                setRowField('padding',          pick(row.padding, 20));
                setRowField('title_text',       row.title_text || '');
                setRowField('title_bg_color',   row.title_bg_color || '#9C27B0');
                setRowField('title_color',      row.title_color || '#FFFFFF');
                setRowField('title_font_size',  pick(row.title_font_size, 11));
                setRowField('title_height',     pick(row.title_height, 60));
            } else if (type === 'pill') {
                setRowField('text',          row.text || '');
                setRowField('bg_color',      row.bg_color || '#9C27B0');
                setRowField('color',         row.color || '#FFFFFF');
                setRowField('font_size',     pick(row.font_size, 18));
                setRowField('width',         pick(row.width, 400));
                setRowField('height',        pick(row.height, 60));
                setRowField('border_radius', pick(row.border_radius, 30));
                setRowField('alignment',     row.alignment || 'centered');
                setRowField('y',             row.y || 0);
                if (row.alignment === 'absolute') {
                    $row.find('.pill-absolute-position-fields').show();
                    setRowField('x', row.x || 0);
                }
            }

            contentRowIndex++;
        });

        return pendingEditors;
    }

    /**
     * Apply an entire settings object (from an imported JSON file or from
     * the "Copy from global" action) to every input on the page. Inverse
     * of collectSettings(): must cover every top-level section and every
     * content-row type serializer produces.
     */
    function applySettings(settings) {
        if (!settings || typeof settings !== 'object') return;

        // Header logo image
        var $header = $('#header_image');
        $header.val(settings.header_image || '');
        syncImagePreviewTile($header);

        // Ticket frame (flat key→value block)
        if (settings.ticket_frame && typeof settings.ticket_frame === 'object') {
            applyKeyValueGroup(settings.ticket_frame, 'ticket_frame');
            $('#ticket_frame_enabled').trigger('change');
            $('select[name="ticket_frame[bg_type]"]').trigger('change');
        }

        // Left / right columns (id="section_key" pattern)
        ['left_column', 'right_column'].forEach(function (section) {
            if (!settings[section] || typeof settings[section] !== 'object') return;
            Object.keys(settings[section]).forEach(function (key) {
                var $el = $('#' + section + '_' + key);
                if ($el.length) setFieldValue($el, settings[section][key]);
            });
        });

        // Top QR block (id="qr_code_key")
        if (settings.qr_code && typeof settings.qr_code === 'object') {
            Object.keys(settings.qr_code).forEach(function (key) {
                var $el = $('#qr_code_' + key);
                if ($el.length) setFieldValue($el, settings.qr_code[key]);
            });
        }

        // Ticket field order + per-field visibility
        applyTicketFieldOrder(
            settings.ticket_field_order_first,
            settings.ticket_field_order_second,
            settings.hidden_default_fields
        );

        // Auto layout toggle (drives visibility of the two column lists)
        if (typeof settings.auto_field_layout !== 'undefined') {
            $('#auto_field_layout')
                .prop('checked', !!parseInt(settings.auto_field_layout, 10))
                .trigger('change');
        }

        // Content rows repeater
        var pendingEditors = applyContentRows(settings.content_rows);

        // Footer text (flat key→value block + TinyMCE sync for `text`)
        if (settings.footer_text && typeof settings.footer_text === 'object') {
            applyKeyValueGroup(settings.footer_text, 'footer_text');
            if (typeof tinymce !== 'undefined') {
                var edFT = tinymce.get('footer_text_content');
                if (edFT && edFT.initialized) {
                    edFT.setContent(settings.footer_text.text || '');
                }
            }
            $('#footer_text_enabled').trigger('change');
        }

        // Re-sync any color-picker whose value was just changed. Avoid
        // re-calling wpColorPicker on inputs that were already wrapped —
        // doing so nests wrappers and breaks layout.
        $('.color-picker').each(function () {
            var $this = $(this);
            if ($this.closest('.wp-picker-container').length) {
                if ($this.val() && typeof $this.iris === 'function') {
                    $this.iris('color', $this.val());
                }
            } else {
                var initial = $this.val();
                $this.wpColorPicker({
                    change: function () { triggerPreviewUpdate(); },
                });
                if (initial) $this.iris('color', initial);
            }
        });

        initializeContentRowStates();

        if (pendingEditors === 0) {
            triggerPreviewUpdate();
        }
    }

    /**
     * Copy settings from global ticket design
     */
    function copyFromGlobal() {
        if (!confirm(ticketDesignerSettings.i18n.confirmCopyGlobal)) {
            return;
        }

        const ticketLang = $('input[name="ticket_lang"]').val() || "";

        $.ajax({
            url: ticketDesignerSettings.ajax_url,
            type: "POST",
            data: {
                action: "get_global_ticket_settings",
                nonce: ticketDesignerSettings.nonce,
                ticket_lang: ticketLang,
            },
            success: function (response) {
                if (response.success) {
                    applySettings(response.data.settings);
                    // Enable product-specific design after copying from global
                    $('input[name="ticket_design_enabled"]').prop("checked", true);
                } else {
                    alert("Error: " + (response.data.message || ticketDesignerSettings.i18n.errorLoadGlobal));
                }
            },
            error: function () {
                alert(ticketDesignerSettings.i18n.errorLoadGlobalRetry);
            },
        });
    }

    // Expose functions globally
    window.exportTicketSettings = exportSettings;
    window.importTicketSettings = importSettings;

    // Initialize copy from global button
    $(document).ready(function () {
        $("#copy-from-global").on("click", copyFromGlobal);
    });
})(jQuery);
