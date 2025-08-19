/**
 * Scan & Pay (n8n) - Settings Page JavaScript
 */

(function($) {
    'use strict';

    const SAN8N_Settings = {
        init: function() {
            this.bindEvents();
            this.initTooltips();
            this.initMediaPicker();
            this.updateBackendVisibility();
            this.renderOrMoveTestUI();
        },

        bindEvents: function() {
            // Test webhook button (fallback our own button)
            $(document).on('click', '#san8n-test-webhook', function(e){ SAN8N_Settings.testWebhook.call(this, e); });
            // Also bind if WC renders a button with field key id
            $(document).on('click', '#woocommerce_scanandpay_n8n_test_webhook', function(e){ SAN8N_Settings.testWebhook.call(this, e); });
            // Support PHP-rendered button via onclick attribute
            window.san8n_test_webhook = () => {
                const el = document.getElementById('woocommerce_scanandpay_n8n_test_webhook') || document.getElementById('san8n-test-webhook');
                return SAN8N_Settings.testWebhook.call(el || window, new $.Event('click'));
            };
            
            // Mode change handlers
            $(document).on('change', '#woocommerce_scanandpay_n8n_blocks_mode', this.handleBlocksModeChange);
            // Backend toggle change
            $(document).on('change', '#woocommerce_scanandpay_n8n_verifier_backend', () => {
                this.updateBackendVisibility();
                this.renderOrMoveTestUI();
            });
            
            // Show/hide advanced settings
            $(document).on('click', '.san8n-toggle-advanced', this.toggleAdvancedSettings);

            // Media picker actions
            $(document).on('click', '#san8n-qr-select', this.openMediaFrame);
            $(document).on('click', '#san8n-qr-clear', function(e) {
                e.preventDefault();
                const $input = $('#woocommerce_scanandpay_n8n_qr_image_url');
                $input.val('').trigger('change');
            });
            $(document).on('change blur', '#woocommerce_scanandpay_n8n_qr_image_url', this.refreshQrPreview);
        },

        initTooltips: function() {
            $('.woocommerce-help-tip').tipTip({
                'attribute': 'data-tip',
                'fadeIn': 50,
                'fadeOut': 50,
                'delay': 200
            });
        },

        testWebhook: function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const $result = $('#san8n-test-result');
            const backend = SAN8N_Settings.getBackend();
            const fields = SAN8N_Settings.getBackendFieldSelectors(backend);
            const $urlInput = SAN8N_Settings.selectFirstExisting(fields.url);
            const $secretInput = SAN8N_Settings.selectFirstExisting(fields.secret);
            const webhookUrl = $urlInput.val();
            const webhookSecret = $secretInput.val();
            
            if (!webhookUrl) {
                $result
                    .removeClass('san8n-test-success')
                    .addClass('san8n-test-error')
                    .text('Please enter a backend URL')
                    .show();
                return;
            }
            
            if (!webhookSecret) {
                $result
                    .removeClass('san8n-test-success')
                    .addClass('san8n-test-error')
                    .text('Please enter a backend secret')
                    .show();
                return;
            }
            // Require HTTPS
            try {
                const u = new URL(webhookUrl);
                if (u.protocol !== 'https:') {
                    $result
                        .removeClass('san8n-test-success')
                        .addClass('san8n-test-error')
                        .text('Backend URL must use HTTPS')
                        .show();
                    return;
                }
            } catch (err) {
                $result
                    .removeClass('san8n-test-success')
                    .addClass('san8n-test-error')
                    .text('Invalid URL')
                    .show();
                return;
            }
            
            // Show loading state
            $button.prop('disabled', true).text(san8n_settings?.i18n?.testing || 'Testing...');
            $result.hide();
            
            // Make test request
            $.ajax({
                url: san8n_settings?.ajax_url || ajaxurl,
                type: 'POST',
                data: {
                    action: 'san8n_test_webhook',
                    nonce: san8n_settings?.nonce
                },
                success: function(resp) {
                    const response = SAN8N_Settings.parseResponse(resp);
                    if (response.success) {
                        $result
                            .removeClass('san8n-test-error')
                            .addClass('san8n-test-success')
                            .html('✓ ' + (response.message || response.data?.message || 'Success'))
                            .show();
                    } else {
                        $result
                            .removeClass('san8n-test-success')
                            .addClass('san8n-test-error')
                            .html('✗ ' + (response.message || response.data?.message || san8n_settings?.i18n?.test_failed || 'Test failed'))
                            .show();
                    }
                },
                error: function() {
                    $result
                        .removeClass('san8n-test-success')
                        .addClass('san8n-test-error')
                        .text('Connection error. Please check your settings.')
                        .show();
                },
                complete: function() {
                    $button.prop('disabled', false).text(SAN8N_Settings.getTestButtonLabel());
                }
            });
        },

        handleBlocksModeChange: function() {
            const mode = $(this).val();
            const $autoSubmitRow = $('#woocommerce_scanandpay_n8n_allow_blocks_autosubmit_experimental').closest('tr');
            const $expressRow = $('#woocommerce_scanandpay_n8n_show_express_only_when_approved').closest('tr');
            
            if (mode === 'express') {
                $autoSubmitRow.hide();
                $expressRow.show();
            } else if (mode === 'autosubmit_experimental') {
                $autoSubmitRow.show();
                $expressRow.hide();
            }
        },

        toggleAdvancedSettings: function(e) {
            e.preventDefault();
            
            const $toggle = $(this);
            const $section = $toggle.closest('.san8n-settings-section');
            const $advanced = $section.find('.san8n-advanced-settings');
            
            if ($advanced.is(':visible')) {
                $advanced.slideUp();
                $toggle.text('Show Advanced Settings ▼');
            } else {
                $advanced.slideDown();
                $toggle.text('Hide Advanced Settings ▲');
            }
        },

        // Initialize media picker UI for the QR image field
        initMediaPicker: function() {
            // Only if field exists (gateway settings page)
            const $field = $('#woocommerce_scanandpay_n8n_qr_image_url');
            if (!$field.length) return;

            // Inject UI once
            if (!$field.data('san8n-ui')) {
                this.renderQrFieldUI($field);
                $field.data('san8n-ui', true);
            }

            // Initial preview state
            this.refreshQrPreview();
        },

        // Render UI controls next to the input field
        renderQrFieldUI: function($field) {
            const html = [
                '<div class="san8n-qr-media">',
                '  <div class="san8n-qr-actions">',
                '    <button type="button" id="san8n-qr-select" class="button">Select image</button>',
                '    <button type="button" id="san8n-qr-clear" class="button">Clear</button>',
                '  </div>',
                '  <img class="san8n-qr-preview" alt="QR preview" style="display:none;" />',
                '</div>'
            ].join('');
            $field.after(html);
        },

        // Open WP media frame and set URL on select
        openMediaFrame: function(e) {
            e.preventDefault();
            // Reuse single frame instance
            if (!window.san8nQrFrame) {
                window.san8nQrFrame = wp.media({
                    title: 'Select QR image',
                    button: { text: 'Use this image' },
                    library: { type: 'image' },
                    multiple: false
                });

                window.san8nQrFrame.on('select', function() {
                    const attachment = window.san8nQrFrame.state().get('selection').first().toJSON();
                    const url = attachment && (attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url);
                    const $input = $('#woocommerce_scanandpay_n8n_qr_image_url');
                    $input.val(url).trigger('change');
                });
            }
            window.san8nQrFrame.open();
        },

        // Update preview image visibility/src based on field value
        refreshQrPreview: function() {
            const $input = $('#woocommerce_scanandpay_n8n_qr_image_url');
            const $img = $input.siblings('.san8n-qr-media').find('.san8n-qr-preview');
            const url = ($input.val() || '').trim();
            if (url) {
                $img.attr('src', url).show();
            } else {
                $img.attr('src', '').hide();
            }
        },

        // Helpers for backend toggle
        getBackend: function() {
            return ($('#woocommerce_scanandpay_n8n_verifier_backend').val() || 'n8n').toLowerCase();
        },

        getBackendFieldSelectors: function(backend) {
            if (backend === 'laravel') {
                return {
                    url: ['#woocommerce_scanandpay_n8n_laravel_verify_url'],
                    secret: ['#woocommerce_scanandpay_n8n_laravel_secret']
                };
            }
            // n8n
            return {
                // Support both historical and current IDs
                url: ['#woocommerce_scanandpay_n8n_n8n_webhook_url', '#woocommerce_scanandpay_n8n_webhook_url'],
                secret: ['#woocommerce_scanandpay_n8n_shared_secret', '#woocommerce_scanandpay_n8n_webhook_secret', '#woocommerce_scanandpay_n8n_n8n_webhook_secret']
            };
        },

        selectFirstExisting: function(selectors) {
            const arr = Array.isArray(selectors) ? selectors : [selectors];
            for (let i = 0; i < arr.length; i++) {
                const $el = $(arr[i]);
                if ($el.length) return $el.first();
            }
            return $();
        },

        updateBackendVisibility: function() {
            const backend = this.getBackend();
            const $n8nRows = $(
                '#woocommerce_scanandpay_n8n_n8n_webhook_url, #woocommerce_scanandpay_n8n_webhook_url, ' +
                '#woocommerce_scanandpay_n8n_shared_secret, #woocommerce_scanandpay_n8n_webhook_secret, #woocommerce_scanandpay_n8n_n8n_webhook_secret'
            ).closest('tr');
            const $laravelRows = $('#woocommerce_scanandpay_n8n_laravel_verify_url, #woocommerce_scanandpay_n8n_laravel_secret').closest('tr');
            if (backend === 'laravel') {
                $n8nRows.hide();
                $laravelRows.show();
            } else {
                $laravelRows.hide();
                $n8nRows.show();
            }
        },

        getTestButtonLabel: function() {
            return 'Test Backend';
        },

        ensureTestUI: function() {
            const $wcBtn = $('#woocommerce_scanandpay_n8n_test_webhook');
            if (!$wcBtn.length && !$('#san8n-test-webhook').length) {
                // Create a fallback button if WC didn't render one
                const html = '<button type="button" id="san8n-test-webhook" class="button">' + this.getTestButtonLabel() + '</button>' +
                             '<div id="san8n-test-result" class="san8n-test-result" style="display:none;"></div>';
                $('#woocommerce_scanandpay_n8n_enabled').closest('tr').after('<tr><th></th><td>' + html + '</td></tr>');
            }
            // Ensure result container exists after whichever button we have
            const $btn = $('#woocommerce_scanandpay_n8n_test_webhook').length ? $('#woocommerce_scanandpay_n8n_test_webhook') : $('#san8n-test-webhook');
            if ($btn.length && !$('#san8n-test-result').length) {
                $('<div id="san8n-test-result" class="san8n-test-result" style="display:none;"></div>').insertAfter($btn);
            }
        },

        renderOrMoveTestUI: function() {
            this.ensureTestUI();
            const backend = this.getBackend();
            const fields = this.getBackendFieldSelectors(backend);
            const $anchor = this.selectFirstExisting(fields.url);
            const $btn = $('#woocommerce_scanandpay_n8n_test_webhook').length ? $('#woocommerce_scanandpay_n8n_test_webhook') : $('#san8n-test-webhook');
            const $res = $('#san8n-test-result');
            if ($btn.length) {
                $btn.text(this.getTestButtonLabel());
            }
            if ($anchor.length && $btn.length) {
                // Move button and result next to the relevant URL field
                $btn.detach();
                $res.detach();
                $anchor.after($btn);
                $btn.after($res);
            }
        },

        parseResponse: function(resp) {
            try {
                if (typeof resp === 'string') {
                    return JSON.parse(resp);
                }
                // If WP returns {success: true, data: {...}}
                if (resp && typeof resp === 'object' && 'success' in resp) {
                    // If message nested in data, normalize
                    if (!('message' in resp) && resp.data && typeof resp.data === 'object' && 'message' in resp.data) {
                        return { success: !!resp.success, message: resp.data.message, data: resp.data };
                    }
                    return resp;
                }
            } catch (e) { /* noop */ }
            // Fallback
            return { success: false, message: 'Unexpected response' };
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        // Only run on settings page
        if ($('#woocommerce_scanandpay_n8n_enabled').length) {
            SAN8N_Settings.init();
            
            // Group advanced settings
            const advancedFields = [
                'retention_days',
                'prevent_double_submit_ms',
                'logging_enabled'
            ];
            
            // Trigger initial mode change to show/hide relevant fields
            $('#woocommerce_scanandpay_n8n_blocks_mode').trigger('change');
        }
    });

    // Add custom styles
    const styles = `
        <style>
            .san8n-test-result {
                margin-top: 10px;
                padding: 10px;
                border-radius: 4px;
                font-size: 13px;
            }
            .san8n-test-success {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            .san8n-test-error {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
            .san8n-validation-feedback {
                display: block;
                margin-top: 5px;
                font-size: 12px;
                font-style: italic;
            }
            .san8n-validation-feedback.valid {
                color: #155724;
            }
            .san8n-validation-feedback.invalid {
                color: #721c24;
            }
            #san8n-test-webhook {
                margin-left: 10px;
            }
            .san8n-advanced-settings {
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #ddd;
            }
            .san8n-toggle-advanced {
                color: #2271b1;
                text-decoration: none;
                font-size: 13px;
            }
            .san8n-toggle-advanced:hover {
                text-decoration: underline;
            }
            /* Media picker UI */
            .san8n-qr-media { margin-top: 6px; }
            .san8n-qr-actions { margin-top: 6px; }
            .san8n-qr-actions .button + .button { margin-left: 8px; }
            .san8n-qr-preview { display:block; max-width:160px; height:auto; margin-top:8px; border:1px solid #ddd; border-radius:3px; }
        </style>
    `;
    
    $('head').append(styles);

})(jQuery);
