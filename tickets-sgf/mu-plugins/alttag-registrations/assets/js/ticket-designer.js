jQuery(document).ready(function($) {
    'use strict';

    const TicketPreview = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $('#load-preview-btn').on('click', this.loadPreview.bind(this));

            // Allow loading preview by pressing Enter on the select
            $('#participant-select').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    TicketPreview.loadPreview();
                }
            });
        },

        loadPreview: function() {
            const participantId = $('#participant-select').val();

            if (!participantId) {
                this.showError('Please select a participant');
                return;
            }

            this.hideError();
            this.showLoading();
            this.hidePreview();

            $.ajax({
                url: ticketDesigner.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_ticket_preview',
                    nonce: ticketDesigner.nonce,
                    participant_id: participantId
                },
                success: this.handleSuccess.bind(this),
                error: this.handleError.bind(this)
            });
        },

        handleSuccess: function(response) {
            this.hideLoading();

            if (response.success && response.data.pdf_url) {
                this.renderPreview(response.data);
            } else {
                this.showError(response.data?.message || 'Failed to load preview');
            }
        },

        handleError: function(xhr, status, error) {
            this.hideLoading();
            this.showError('Error: ' + error);
            console.error('AJAX Error:', xhr, status, error);
        },

        renderPreview: function(data) {
            // Set participant info
            if (data.participant_info) {
                const info = data.participant_info;
                let infoHtml = '<div class="participant-info-box">';
                infoHtml += '<h3>Preview for: ' + this.escapeHtml(info.name) + '</h3>';
                infoHtml += '<p><strong>Email:</strong> ' + this.escapeHtml(info.email) + '</p>';
                if (info.company) {
                    infoHtml += '<p><strong>Company:</strong> ' + this.escapeHtml(info.company) + '</p>';
                }
                infoHtml += '</div>';
                $('#participant-info').html(infoHtml);
            }

            // Load PDF in iframe
            $('#ticket-pdf-iframe').attr('src', data.pdf_url);

            this.showPreview();
        },

        showLoading: function() {
            $('#preview-loading').show();
        },

        hideLoading: function() {
            $('#preview-loading').hide();
        },

        showPreview: function() {
            $('#ticket-preview-section').slideDown();
        },

        hidePreview: function() {
            $('#ticket-preview-section').hide();
        },

        showError: function(message) {
            $('#preview-error p').text(message);
            $('#preview-error').show();
        },

        hideError: function() {
            $('#preview-error').hide();
        },

        escapeHtml: function(text) {
            if (!text) return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }
    };

    TicketPreview.init();
});
