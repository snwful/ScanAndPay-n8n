/**
 * Scan & Pay (n8n) - Classic Checkout JavaScript
 */

(function($) {
    'use strict';

    let isProcessing = false;
    let isApproved = false;
    let preventDoubleSubmit = false;
    let autoSubmitTimer = null;
    let qrCountdownInterval = null;
    let qrRefreshTimeout = null;
    let lastExpiresAtMs = null;
    let qrRequestInFlight = false;
    let qrRequestQueued = false;
    let lastQRKey = null;

    const SAN8N_Checkout = {
        init: function() {
            this.bindEvents();
            this.initializeState();
            // Attempt initial QR fetch (debounced)
            this.debouncedFetchQR();
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
            // Some themes/plugins don't trigger payment_method_selected reliably; also watch radio change
            $(document).on('change', 'input[name="payment_method"]', this.handlePaymentMethodChange);

            // When checkout totals update (coupons/shipping), refresh QR
            $(document.body).on('updated_checkout', this.debouncedFetchQR);
            
            // Checkout error
            $(document.body).on('checkout_error', this.handleCheckoutError);
            
            // Before checkout validation
            $(document).on('checkout_place_order_' + san8n_params.gateway_id, this.validateBeforeSubmit);

        },

        initializeState: function() {
            // Check if payment method is selected
            if ($('#payment_method_' + san8n_params.gateway_id).is(':checked')) {
                this.showPaymentFields();
                this.debouncedFetchQR();
            }
        },

        // Debounced fetch to avoid spamming backend on rapid updates
        debouncedFetchQR: (function() {
            let t = null;
            return function() {
                if (t) clearTimeout(t);
                t = setTimeout(function() {
                    SAN8N_Checkout.fetchAndRenderQR();
                }, 500);
            };
        })(),

        getOrderTotal: function() {
            // Try to read total from DOM
            const $amt = $('#order_review .order-total .amount');
            if ($amt.length) {
                const raw = $amt.text().replace(/[^0-9.,]/g, '');
                if (raw.indexOf(',') !== -1 && raw.indexOf('.') === -1) {
                    return parseFloat(raw.replace(',', '.')) || 0;
                }
                return parseFloat(raw.replace(/,/g, '')) || 0;
            }
            return 0;
        },

        fetchAndRenderQR: function() {
            // Only when our method is selected and fields exist
            if (!$('#payment_method_' + san8n_params.gateway_id).is(':checked')) return;
            if (!$('#san8n-payment-fields').length) return;

            const sessionToken = $('#san8n-session-token').val();
            const orderId = $('form.checkout').find('input[name="order_id"]').val() || 0;
            const total = SAN8N_Checkout.getOrderTotal();

            // Dedupe by payload key to avoid duplicate webhook calls when nothing changed
            const reqKey = String(sessionToken) + '|' + String(orderId) + '|' + (Number(total) || 0).toFixed(2);
            if (lastQRKey === reqKey) {
                // Nothing changed; skip
                SAN8N_Checkout.debug('QR fetch skipped (duplicate key)', { reqKey });
                return;
            }

            // In-flight guard to prevent overlapping requests
            if (qrRequestInFlight) {
                qrRequestQueued = true;
                SAN8N_Checkout.debug('QR fetch deferred (in-flight)', { reqKey });
                return;
            }
            qrRequestInFlight = true;
            lastQRKey = reqKey;

            // Even in media_picker (static QR) mode, still hit backend for ref_code/expiry so UI can show them
            if (san8n_params.qr_source === 'media_picker') {
                $('#san8n-payment-fields .san8n-amount-display').text(
                    (san8n_params.i18n.amount_label || 'Amount: %s THB').replace('%s', (parseFloat(total) || 0).toFixed(2))
                );
            }

            SAN8N_Checkout.clearQrTimers();
            SAN8N_Checkout.debug('Fetching QR...', { orderId, total, sessionToken });

            const payload = {
                session_token: sessionToken,
                order_id: parseInt(orderId, 10) || 0,
                order_total: total
            };

            $.ajax({
                url: san8n_params.rest_url + '/qr-proxy',
                type: 'POST',
                data: JSON.stringify(payload),
                processData: false,
                contentType: 'application/json',
                xhrFields: { withCredentials: true },
                headers: { 'X-WP-Nonce': san8n_params.nonce },
                timeout: parseInt(san8n_params.verify_timeout_ms || 9000, 10),
                success: function(response) {
                    SAN8N_Checkout.debug('QR fetch success', response);
                    if (response && response.qr_url) {
                        $('#san8n-payment-fields .san8n-qr-container img.san8n-qr-placeholder').attr('src', response.qr_url);
                    }
                    if (response && typeof response.amount !== 'undefined') {
                        const amt = parseFloat(response.amount) || total;
                        $('#san8n-payment-fields .san8n-amount-display').text(
                            (san8n_params.i18n.amount_label || 'Amount: %s THB').replace('%s', amt.toFixed(2))
                        );
                    }

                    // Show reference code if provided
                    if (response && response.ref_code) {
                        const label = (san8n_params.i18n_ref && san8n_params.i18n_ref.ref_code_label) ? san8n_params.i18n_ref.ref_code_label : 'Reference: %s';
                        $('#san8n-payment-fields .san8n-ref-code')
                            .text(label.replace('%s', String(response.ref_code)))
                            .show();
                    } else {
                        $('#san8n-payment-fields .san8n-ref-code').hide().text('');
                    }

                    // Start expiry countdown if provided, else fallback to configured seconds
                    let expiresAtMs = null;
                    if (response && response.expires_epoch) {
                        const epoch = parseInt(response.expires_epoch, 10);
                        // Heuristic: seconds vs milliseconds
                        expiresAtMs = epoch > 1e12 ? epoch : epoch * 1000;
                    } else if (san8n_params.qr_expiry_seconds) {
                        expiresAtMs = Date.now() + (parseInt(san8n_params.qr_expiry_seconds, 10) * 1000);
                    }
                    if (expiresAtMs) {
                        SAN8N_Checkout.startQrCountdown(expiresAtMs);
                    } else {
                        $('.san8n-qr-countdown').hide();
                    }
                },
                error: function(xhr, textStatus, err) {
                    SAN8N_Checkout.debug('QR fetch error', { textStatus, err, status: xhr && xhr.status, body: xhr && xhr.responseText });
                    // Leave placeholder on failure
                },
                complete: function() {
                    // Release in-flight flag and process any queued refresh once
                    qrRequestInFlight = false;
                    if (qrRequestQueued) {
                        qrRequestQueued = false;
                        SAN8N_Checkout.debouncedFetchQR();
                    }
                }
            });
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
                timeout: parseInt(san8n_params.verify_timeout_ms || 9000, 10),
                success: function(response) {
                    SAN8N_Checkout.debug('Verify success', response);
                    if (response.status === 'approved') {
                        SAN8N_Checkout.handleApproval(response);
                    } else {
                        SAN8N_Checkout.handleRejection(response);
                    }
                },
                error: function(xhr, textStatus) {
                    let message = san8n_params.i18n.error;
                    if (textStatus === 'timeout') {
                        message = san8n_params.i18n.timeout || message;
                    } else if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                        message = xhr.responseJSON.message;
                    }
                    SAN8N_Checkout.debug('Verify error', { textStatus, status: xhr && xhr.status, body: xhr && xhr.responseText, message });
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
            SAN8N_Checkout.debug('Payment approved', { reference_id: response.reference_id });
            
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
            SAN8N_Checkout.debug('Payment rejected', response);
            $('#san8n-verify-button').prop('disabled', false);
        },

        

        handlePaymentMethodChange: function() {
            if ($('#payment_method_' + san8n_params.gateway_id).is(':checked')) {
                SAN8N_Checkout.showPaymentFields();
                SAN8N_Checkout.debouncedFetchQR();
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
            SAN8N_Checkout.clearQrTimers();
        },

        validateBeforeSubmit: function() {
            // In slipless mode, allow submission without approval
            if (san8n_params.verification_mode === 'slipless') {
                return true;
            }
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
            SAN8N_Checkout.clearQrTimers();
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
        },

        // --- QR expiry countdown helpers ---
        startQrCountdown: function(expiresAtMs) {
            lastExpiresAtMs = expiresAtMs;
            const $cd = $('.san8n-qr-countdown');
            if (!$cd.length) return;

            const update = function() {
                const now = Date.now();
                const remainingMs = Math.max(0, expiresAtMs - now);
                if (remainingMs <= 0) {
                    $cd.text(san8n_params.i18n.qr_expired_refreshing || 'QR expired, refreshing...');
                    SAN8N_Checkout.scheduleQrRefresh(100);
                    clearInterval(qrCountdownInterval);
                    qrCountdownInterval = null;
                    return;
                }
                const secs = Math.ceil(remainingMs / 1000);
                $cd.text((san8n_params.i18n.qr_expires_in || 'QR expires in %s').replace('%s', SAN8N_Checkout.formatSeconds(secs)));
            };

            // Show and start interval
            $cd.show();
            if (qrCountdownInterval) clearInterval(qrCountdownInterval);
            update();
            qrCountdownInterval = setInterval(update, 1000);

            // Also schedule a refresh right after expiry as a safeguard
            const delay = Math.max(0, expiresAtMs - Date.now() + 150);
            SAN8N_Checkout.scheduleQrRefresh(delay);
        },

        clearQrTimers: function() {
            if (qrCountdownInterval) {
                clearInterval(qrCountdownInterval);
                qrCountdownInterval = null;
            }
            if (qrRefreshTimeout) {
                clearTimeout(qrRefreshTimeout);
                qrRefreshTimeout = null;
            }
            lastExpiresAtMs = null;
        },

        scheduleQrRefresh: function(delayMs) {
            if (qrRefreshTimeout) clearTimeout(qrRefreshTimeout);
            qrRefreshTimeout = setTimeout(function() {
                $('.san8n-qr-countdown').text(san8n_params.i18n.qr_refreshing || 'Refreshing QR...');
                // Allow refresh even if amount/session/order unchanged
                lastQRKey = null;
                SAN8N_Checkout.fetchAndRenderQR();
            }, Math.max(0, delayMs));
        },

        formatSeconds: function(totalSecs) {
            const s = Math.max(0, parseInt(totalSecs, 10) || 0);
            const m = Math.floor(s / 60);
            const r = s % 60;
            if (m <= 0) return r + 's';
            return m + 'm ' + r + 's';
        },

        // --- Debug helper ---
        debug: function(msg, payload) {
            try {
                if (san8n_params && san8n_params.debug && window && window.console) {
                    if (payload !== undefined) {
                        console.debug('[SAN8N]', msg, payload);
                    } else {
                        console.debug('[SAN8N]', msg);
                    }
                }
            } catch (e) { /* no-op */ }
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        SAN8N_Checkout.init();
    });

})(jQuery);
