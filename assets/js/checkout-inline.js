/**
 * Scan & Pay (n8n) - Classic Checkout JavaScript
 */

(function($) {
    'use strict';

    let isProcessing = false;
    let isApproved = false;
    let preventDoubleSubmit = false;
    let autoSubmitTimer = null;

    const SAN8N_Checkout = {
        init: function() {
            this.bindEvents();
            this.initializeState();
        },

        bindEvents: function() {
            // File upload change
            $(document).on('change', '#san8n-slip-upload', this.handleFileSelect);
            
            // Remove slip button
            $(document).on('click', '.san8n-remove-slip', this.handleRemoveSlip);
            
            // Verify button click
            $(document).on('click', '#san8n-verify-button', this.handleVerify);
            
            // Payment method change
            $(document.body).on('payment_method_selected', this.handlePaymentMethodChange);
            
            // Checkout error
            $(document.body).on('checkout_error', this.handleCheckoutError);
            
            // Before checkout validation
            $(document).on('checkout_place_order_' + san8n_params.gateway_id, this.validateBeforeSubmit);

        },

        initializeState: function() {
            // Check if payment method is selected
            if ($('#payment_method_' + san8n_params.gateway_id).is(':checked')) {
                this.showPaymentFields();
            }
        },

        

        handleFileSelect: function(e) {
            const file = e.target.files[0];
            if (!file) return;

            // Validate file type
            const validTypes = ['image/jpeg', 'image/jpg', 'image/png'];
            if (!validTypes.includes(file.type)) {
                SAN8N_Checkout.showError(san8n_params.i18n.invalid_file_type);
                e.target.value = '';
                return;
            }

            // Validate file size
            const maxSize = parseInt($(this).data('max-size'));
            if (file.size > maxSize) {
                SAN8N_Checkout.showError(san8n_params.i18n.file_too_large);
                e.target.value = '';
                return;
            }

            // Preview image
            const reader = new FileReader();
            reader.onload = function(event) {
                $('#san8n-preview-image').attr('src', event.target.result);
                $('.san8n-upload-preview').show();
                $('#san8n-verify-button').prop('disabled', false);
            };
            reader.readAsDataURL(file);
        },

        handleRemoveSlip: function(e) {
            e.preventDefault();
            $('#san8n-slip-upload').val('');
            $('.san8n-upload-preview').hide();
            $('#san8n-verify-button').prop('disabled', true);
            $('#san8n-approval-status').val('');
            SAN8N_Checkout.clearStatus();
            isApproved = false;
        },

        handleVerify: function(e) {
            e.preventDefault();
            
            if (isProcessing) return;
            
            const fileInput = $('#san8n-slip-upload')[0];
            if (!fileInput.files[0]) {
                SAN8N_Checkout.showError(san8n_params.i18n.upload_required);
                return;
            }

            SAN8N_Checkout.performVerification(fileInput.files[0]);
        },

        performVerification: function(file) {
            isProcessing = true;
            
            // Show loading state
            $('#san8n-verify-button').prop('disabled', true);
            $('.san8n-spinner').show();
            SAN8N_Checkout.showStatus(san8n_params.i18n.verifying, 'info');

            // Prepare form data
            const formData = new FormData();
            formData.append('slip_image', file);
            formData.append('session_token', $('#san8n-session-token').val());
            const orderId = $('form.checkout').find('input[name="order_id"]').val() || 0;
            formData.append('order_id', orderId);
            const total = $('#order_review .order-total .amount').text().replace(/[^\d.]/g, '');
            formData.append('order_total', total);

            // Make AJAX request
            $.ajax({
                url: san8n_params.rest_url + '/verify-slip',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhrFields: { withCredentials: true },
                headers: {
                    'X-WP-Nonce': san8n_params.nonce
                },
                success: function(response) {
                    if (response.status === 'approved') {
                        SAN8N_Checkout.handleApproval(response);
                    } else {
                        SAN8N_Checkout.handleRejection(response);
                    }
                },
                error: function(xhr) {
                    let message = san8n_params.i18n.error;
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        message = xhr.responseJSON.message;
                    }
                    SAN8N_Checkout.showError(message);
                },
                complete: function() {
                    isProcessing = false;
                    $('.san8n-spinner').hide();
                    $('#san8n-verify-button').prop('disabled', false);
                }
            });
        },

        handleApproval: function(response) {
            isApproved = true;
            $('#san8n-approval-status').val('approved');
            $('#san8n-reference-id').val(response.reference_id || '');
            
            SAN8N_Checkout.showStatus(san8n_params.i18n.approved, 'success');
            
            // Disable verify button after approval
            $('#san8n-verify-button').prop('disabled', true).text('✓ ' + san8n_params.i18n.approved);
            
            // Auto-submit if enabled
            if (san8n_params.auto_submit && !preventDoubleSubmit) {
                autoSubmitTimer = setTimeout(function() {
                    if (!preventDoubleSubmit) {
                        preventDoubleSubmit = true;
                        SAN8N_Checkout.showStatus(san8n_params.i18n.approved + ' ' + san8n_params.i18n.processing_order, 'success');
                        
                        // Trigger checkout submission
                        $('#place_order').trigger('click');
                        
                        // Reset flag after delay
                        setTimeout(function() {
                            preventDoubleSubmit = false;
                        }, san8n_params.prevent_double_submit_ms);
                    }
                }, 500);
            }
        },

        handleRejection: function(response) {
            isApproved = false;
            $('#san8n-approval-status').val('rejected');
            
            let message = san8n_params.i18n.rejected;
            if (response.reason) {
                message += ' ' + response.reason;
            }
            
            SAN8N_Checkout.showError(message);
            $('#san8n-verify-button').prop('disabled', false);
        },

        

        handlePaymentMethodChange: function() {
            if ($('#payment_method_' + san8n_params.gateway_id).is(':checked')) {
                SAN8N_Checkout.showPaymentFields();
            } else {
                SAN8N_Checkout.hidePaymentFields();
            }
        },

        handleCheckoutError: function() {
            // Clear auto-submit if checkout validation failed
            if (autoSubmitTimer) {
                clearTimeout(autoSubmitTimer);
                autoSubmitTimer = null;
            }
            preventDoubleSubmit = false;
        },

        validateBeforeSubmit: function() {
            if (!isApproved) {
                SAN8N_Checkout.showError(san8n_params.i18n.verify_required || 'Please verify your payment before placing the order.');
                return false;
            }
            return true;
        },

        showPaymentFields: function() {
            $('#san8n-payment-fields').slideDown();
        },

        hidePaymentFields: function() {
            $('#san8n-payment-fields').slideUp();
        },

        showStatus: function(message, type) {
            const $container = $('.san8n-status-container');
            const $message = $('.san8n-status-message');
            
            $message
                .removeClass('san8n-info san8n-success san8n-warning san8n-error')
                .addClass('san8n-' + type)
                .html(message)
                .show();
            
            // Update aria-live region for accessibility
            $container.attr('aria-label', message);
        },

        showError: function(message) {
            this.showStatus(message, 'error');
        },

        clearStatus: function() {
            $('.san8n-status-message').hide().html('');
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        SAN8N_Checkout.init();
    });

})(jQuery);
