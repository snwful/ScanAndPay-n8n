/**
 * Scan & Pay (n8n) - Blocks Integration
 */

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { __ } = window.wp.i18n;
const { useState, useEffect, useCallback } = window.wp.element;
const { decodeEntities } = window.wp.htmlEntities;

const settings = getSetting('scanandpay_n8n_data', {});
const label = decodeEntities(settings.title) || __('Scan & Pay (n8n)', 'scanandpay-n8n');
const description = decodeEntities(settings.description || '');

const SAN8N_BlocksContent = ({ eventRegistration, emitResponse }) => {
    const { onPaymentSetup, onCheckoutValidation } = eventRegistration;
    const [slipFile, setSlipFile] = useState(null);
    const [previewUrl, setPreviewUrl] = useState('');
    const [isVerifying, setIsVerifying] = useState(false);
    const [verificationStatus, setVerificationStatus] = useState(null);
    const [statusMessage, setStatusMessage] = useState('');
    const [referenceId, setReferenceId] = useState('');
    const [approvedAmount, setApprovedAmount] = useState(0);
    const [showExpressButton, setShowExpressButton] = useState(false);

    // Get cart total
    const cartTotal = window.wc.wcBlocksData.getSetting('cartTotals', {}).total_price / 100;

    useEffect(() => {
        // Handle payment setup
        const unsubscribe = onPaymentSetup(() => {
            if (verificationStatus !== 'approved') {
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: settings.i18n.upload_required,
                };
            }

            return {
                type: emitResponse.responseTypes.SUCCESS,
                meta: {
                    paymentMethodData: {
                        san8n_approval_status: verificationStatus,
                        san8n_reference_id: referenceId,
                        san8n_approved_amount: approvedAmount,
                    },
                },
            };
        });

        return unsubscribe;
    }, [onPaymentSetup, emitResponse, verificationStatus, referenceId, approvedAmount]);

    useEffect(() => {
        // Handle checkout validation
        const unsubscribe = onCheckoutValidation(() => {
            if (verificationStatus !== 'approved') {
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: settings.i18n.upload_required,
                };
            }
            return true;
        });

        return unsubscribe;
    }, [onCheckoutValidation, emitResponse, verificationStatus]);

    const handleFileSelect = useCallback((event) => {
        const file = event.target.files[0];
        if (!file) return;

        // Validate file type
        const validTypes = ['image/jpeg', 'image/jpg', 'image/png'];
        if (!validTypes.includes(file.type)) {
            setStatusMessage(settings.i18n.invalid_file_type);
            event.target.value = '';
            return;
        }

        // Validate file size
        if (file.size > settings.settings.max_file_size) {
            setStatusMessage(settings.i18n.file_too_large);
            event.target.value = '';
            return;
        }

        setSlipFile(file);

        // Create preview
        const reader = new FileReader();
        reader.onload = (e) => {
            setPreviewUrl(e.target.result);
        };
        reader.readAsDataURL(file);
    }, []);

    const handleRemoveSlip = useCallback(() => {
        setSlipFile(null);
        setPreviewUrl('');
        setVerificationStatus(null);
        setStatusMessage('');
        setReferenceId('');
        setShowExpressButton(false);
    }, []);

    const handleVerify = useCallback(async () => {
        if (!slipFile) {
            setStatusMessage(settings.i18n.upload_required);
            return;
        }

        setIsVerifying(true);
        setStatusMessage(settings.i18n.verifying);

        const formData = new FormData();
        formData.append('slip_image', slipFile);
        formData.append('session_token', Date.now().toString());
        formData.append('order_id', 0);
        formData.append('order_total', cartTotal);

        try {
            const response = await fetch(settings.rest_url + '/verify-slip', {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': settings.nonce,
                },
                body: formData,
            });

            const data = await response.json();

            if (response.ok) {
                handleVerificationResponse(data);
            } else {
                setVerificationStatus('error');
                setStatusMessage(data.message || settings.i18n.error);
            }
        } catch (error) {
            setVerificationStatus('error');
            setStatusMessage(settings.i18n.error);
        } finally {
            setIsVerifying(false);
        }
    }, [slipFile, cartTotal]);

    const handleVerificationResponse = (response) => {
        setVerificationStatus(response.status);
        
        if (response.status === 'approved') {
            setReferenceId(response.reference_id || '');
            setApprovedAmount(response.approved_amount || 0);
            setStatusMessage(settings.i18n.approved);
            
            // Show express button if configured
            if (settings.settings.blocks_mode === 'express' && 
                settings.settings.show_express_only_when_approved) {
                setShowExpressButton(true);
            }
            
            // Auto-submit if experimental mode is enabled
            if (settings.settings.blocks_mode === 'autosubmit_experimental' && 
                settings.settings.allow_blocks_autosubmit_experimental) {
                setTimeout(() => {
                    handleExpressPayment();
                }, 500);
            }
        } else if (response.status === 'rejected') {
            setStatusMessage(response.reason || settings.i18n.rejected);
        } else {
            setStatusMessage('Verification pending...');
        }
    };

    const handleExpressPayment = useCallback(() => {
        // Trigger Blocks checkout submission
        const submitButton = document.querySelector('.wc-block-components-checkout-place-order-button');
        if (submitButton) {
            submitButton.click();
        }
    }, []);

    return (
        <div className="san8n-blocks-payment-fields">
            {/* QR Code Section */}
            <div className="san8n-qr-section">
                <h4>{settings.i18n.scan_qr}</h4>
                <div className="san8n-qr-container">
                    <img 
                        src={settings.settings.qr_placeholder} 
                        alt="PromptPay QR Code"
                        className="san8n-qr-placeholder"
                    />
                    <div className="san8n-amount-display">
                        {settings.i18n.amount_label.replace('%s', cartTotal.toFixed(2))}
                    </div>
                </div>
            </div>

            {/* Upload Section */}
            <div className="san8n-upload-section">
                <h4>{settings.i18n.upload_slip}</h4>
                <div className="san8n-upload-container">
                    {!previewUrl ? (
                        <>
                            <input
                                type="file"
                                accept="image/jpeg,image/jpg,image/png"
                                onChange={handleFileSelect}
                                disabled={isVerifying}
                            />
                            <div className="san8n-upload-info">
                                {settings.i18n.accepted_formats.replace('%d', 
                                    Math.round(settings.settings.max_file_size / (1024 * 1024))
                                )}
                            </div>
                        </>
                    ) : (
                        <div className="san8n-upload-preview">
                            <img src={previewUrl} alt="Slip preview" />
                            <button 
                                type="button" 
                                onClick={handleRemoveSlip}
                                className="san8n-remove-slip"
                                disabled={isVerifying}
                            >
                                {settings.i18n.remove}
                            </button>
                        </div>
                    )}
                </div>
            </div>

            {/* Verify Section */}
            <div className="san8n-verify-section">
                {verificationStatus !== 'approved' && (
                    <button
                        type="button"
                        onClick={handleVerify}
                        disabled={!slipFile || isVerifying}
                        className="san8n-verify-button components-button is-primary"
                    >
                        {isVerifying ? '...' : settings.i18n.verify_payment}
                    </button>
                )}

                {/* Express Payment Button */}
                {showExpressButton && verificationStatus === 'approved' && (
                    <button
                        type="button"
                        onClick={handleExpressPayment}
                        className="san8n-express-button components-button is-primary"
                    >
                        {settings.i18n.pay_now}
                    </button>
                )}

                {/* Status Message */}
                {statusMessage && (
                    <div 
                        className={`san8n-status-message san8n-status-${verificationStatus}`}
                        role="status"
                        aria-live="polite"
                    >
                        {statusMessage}
                    </div>
                )}
            </div>
        </div>
    );
};

const SAN8N_BlocksLabel = () => {
    return (
        <span className="san8n-blocks-label">
            {label}
        </span>
    );
};

// Register payment method
registerPaymentMethod({
    name: 'scanandpay_n8n',
    label: <SAN8N_BlocksLabel />,
    content: <SAN8N_BlocksContent />,
    edit: <SAN8N_BlocksContent />,
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports || [],
    },
});
