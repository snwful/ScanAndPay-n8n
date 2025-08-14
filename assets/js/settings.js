/**
 * Scan & Pay (n8n) - Settings Page JavaScript
 */

(function($) {
    'use strict';

    const SAN8N_Settings = {
        init: function() {
            this.bindEvents();
            this.initTooltips();
        },

        bindEvents: function() {
            // Test webhook button
            $(document).on('click', '#san8n-test-webhook', this.testWebhook);

            // Mode change handlers
            $(document).on('change', '#woocommerce_scanandpay_n8n_blocks_mode', this.handleBlocksModeChange);

            // Media Library picker
            $(document).on('click', '#san8n-select-qr-image', this.openMediaPicker);
            $(document).on('click', '#san8n-remove-qr-image', this.removeQrImage);

            // Show/hide advanced settings
            $(document).on('click', '.san8n-toggle-advanced', this.toggleAdvancedSettings);
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
            const webhookUrl = $('#woocommerce_scanandpay_n8n_webhook_url').val();
            const webhookSecret = $('#woocommerce_scanandpay_n8n_webhook_secret').val();
            
            if (!webhookUrl) {
                $result
                    .removeClass('san8n-test-success')
                    .addClass('san8n-test-error')
                    .text('Please enter a webhook URL')
                    .show();
                return;
            }
            
            if (!webhookSecret) {
                $result
                    .removeClass('san8n-test-success')
                    .addClass('san8n-test-error')
                    .text('Please enter a webhook secret')
                    .show();
                return;
            }
            
            // Show loading state
            $button.prop('disabled', true).text('Testing...');
            $result.hide();
            
            // Make test request
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'san8n_test_webhook',
                    nonce: san8n_settings.nonce,
                    webhook_url: webhookUrl,
                    webhook_secret: webhookSecret
                },
                success: function(response) {
                    if (response.success) {
                        $result
                            .removeClass('san8n-test-error')
                            .addClass('san8n-test-success')
                            .html('✓ ' + response.data.message)
                            .show();
                    } else {
                        $result
                            .removeClass('san8n-test-success')
                            .addClass('san8n-test-error')
                            .html('✗ ' + (response.data.message || 'Test failed'))
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
                    $button.prop('disabled', false).text('Test Webhook');
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

        openMediaPicker: function(e) {
            e.preventDefault();

            if (SAN8N_Settings.frame) {
                SAN8N_Settings.frame.open();
                return;
            }

            SAN8N_Settings.frame = wp.media({
                title: san8n_settings.i18n.select_image,
                button: { text: san8n_settings.i18n.select_image },
                multiple: false
            });

            SAN8N_Settings.frame.on('select', function() {
                const attachment = SAN8N_Settings.frame.state().get('selection').first().toJSON();
                $('#woocommerce_scanandpay_n8n_qr_image_id').val(attachment.id);
                $('#san8n-qr-preview img').attr('src', attachment.url);
                $('#san8n-qr-preview, #san8n-remove-qr-image').show();
            });

            SAN8N_Settings.frame.open();
        },

        removeQrImage: function(e) {
            e.preventDefault();
            $('#woocommerce_scanandpay_n8n_qr_image_id').val('');
            $('#san8n-qr-preview').hide();
            $('#san8n-remove-qr-image').hide();
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
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        // Only run on settings page
        if ($('#woocommerce_scanandpay_n8n_enabled').length) {
            SAN8N_Settings.init();

            // Add test button after webhook URL field
            const testButton = '<button type="button" id="san8n-test-webhook" class="button">Test Webhook</button>' +
                             '<div id="san8n-test-result" class="san8n-test-result" style="display:none;"></div>';
            $('#woocommerce_scanandpay_n8n_webhook_url').after(testButton);

            // Add QR image picker UI
            const qrPicker = '<button type="button" class="button" id="san8n-select-qr-image">' + san8n_settings.i18n.select_image + '</button>' +
                             '<button type="button" class="button" id="san8n-remove-qr-image" style="display:none;">' + san8n_settings.i18n.remove_image + '</button>' +
                             '<div id="san8n-qr-preview" style="margin-top:10px;display:none;"><img src="" style="max-width:150px;" /></div>';
            $('#woocommerce_scanandpay_n8n_qr_image_id').after(qrPicker);

            if (san8n_settings.qr_image_url) {
                $('#san8n-qr-preview img').attr('src', san8n_settings.qr_image_url);
                $('#san8n-qr-preview, #san8n-remove-qr-image').show();
            }

            // Group advanced settings
            const advancedFields = [
                'retention_days',
                'prevent_double_submit_ms',
                'log_level'
            ];

            advancedFields.forEach(function(fieldId) {
                const $row = $('#woocommerce_scanandpay_n8n_' + fieldId).closest('tr');
                $row.addClass('san8n-advanced-settings').hide();
            });

            // Add toggle button
            const toggleButton = '<a href="#" class="san8n-toggle-advanced">Show Advanced Settings ▼</a>';
            $('#woocommerce_scanandpay_n8n_max_file_size').closest('tr').before('<tr><td colspan="2">' + toggleButton + '</td></tr>');

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
        </style>
    `;
    
    $('head').append(styles);

})(jQuery);
