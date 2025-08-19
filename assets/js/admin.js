/**
 * Scan & Pay (n8n) - Admin JavaScript
 */

(function($) {
    'use strict';

    const SAN8N_Admin = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $(document).on('click', '.san8n-action-button', this.handleAction);
        },

        handleAction: function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const action = $button.data('action');
            const orderId = $button.data('order-id');
            const nonce = $('#san8n_admin_nonce').val();
            
            let confirmMessage = '';
            switch(action) {
                case 'approve':
                    confirmMessage = san8n_admin.i18n.confirm_approve;
                    break;
                case 'reject':
                    confirmMessage = san8n_admin.i18n.confirm_reject;
                    break;
                default:
                    // Unknown/unsupported action in checkout-only flow
                    return;
            }
            
            if (!confirm(confirmMessage)) {
                return;
            }
            
            // Show processing state
            $button.prop('disabled', true).text(san8n_admin.i18n.processing);
            $('.san8n-action-result').hide();
            
            // Prepare data
            const data = {
                action: 'san8n_' + action,
                order_id: orderId,
                nonce: nonce
            };
            
            // Add reason for rejection
            if (action === 'reject') {
                const reason = prompt('Please provide a reason for rejection:');
                if (!reason) {
                    $button.prop('disabled', false).text($button.text());
                    return;
                }
                data.reason = reason;
            }
            
            // Make AJAX request
            $.post(san8n_admin.ajax_url, data, function(response) {
                if (response.success) {
                    $('.san8n-action-result')
                        .removeClass('notice-error')
                        .addClass('notice-success')
                        .html(response.message || san8n_admin.i18n.success)
                        .show();
                    
                    // Reload meta box content after 2 seconds
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $('.san8n-action-result')
                        .removeClass('notice-success')
                        .addClass('notice-error')
                        .html(response.message || san8n_admin.i18n.error)
                        .show();
                }
            }).fail(function() {
                $('.san8n-action-result')
                    .removeClass('notice-success')
                    .addClass('notice-error')
                    .html(san8n_admin.i18n.error)
                    .show();
            }).always(function() {
                $button.prop('disabled', false).text($button.data('original-text') || $button.text());
            });
        }
    };

    $(document).ready(function() {
        SAN8N_Admin.init();
    });

})(jQuery);
