/**
 * Scan & Pay (n8n) - Blocks Integration
 */

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { __ } = window.wp.i18n;
const { useState, useEffect, useCallback, useRef } = window.wp.element;
const { decodeEntities } = window.wp.htmlEntities;
const useSelect = (window.wp && window.wp.data && window.wp.data.useSelect) ? window.wp.data.useSelect : null;

const settings = getSetting('scanandpay_n8n_data', {});
const label = decodeEntities(settings.title) || __('Scan & Pay (n8n)', 'scanandpay-n8n');
const description = decodeEntities(settings.description || '');
const qrSource = (settings && settings.settings && settings.settings.qr_source) ? settings.settings.qr_source : 'n8n';

// Dynamically fetch PromptPay QR via plugin REST proxy

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
    const [qrUrl, setQrUrl] = useState(settings.settings.qr_placeholder);
    const [qrAmount, setQrAmount] = useState(0);
    const [refCode, setRefCode] = useState('');
    const [expiresEpoch, setExpiresEpoch] = useState(null);
    const [countdown, setCountdown] = useState('');
    const inflightRef = useRef(false);
    const lastKeyRef = useRef(null);
    const abortRef = useRef(null);
    const [sessionToken] = useState(() => {
        try {
            if (window.crypto && window.crypto.randomUUID) {
                return window.crypto.randomUUID();
            }
        } catch (e) {}
        return (Date.now().toString(36) + Math.random().toString(36).slice(2));
    });

    // Get cart total (reactive via data store; fallback to static setting)
    const totals = useSelect ? useSelect((select) => select('wc/store').getCartTotals(), []) : null;
    const cartTotal = (totals && typeof totals.total_price === 'number')
        ? totals.total_price / 100
        : (((window.wc && window.wc.wcBlocksData && window.wc.wcBlocksData.getSetting('cartTotals', {}).total_price) || 0) / 100);

    useEffect(() => {
        // Fetch QR whenever total or session changes, with dedupe and cache reuse
        const now = () => Math.floor(Date.now() / 1000);
        const key = `${sessionToken}|${(Number(cartTotal) || 0).toFixed(2)}`;

        // If static mode, do not fetch; keep provided image and just update amount
        if (qrSource === 'media_picker') {
            setQrAmount(cartTotal);
            return;
        }

        // If we already have a valid, not-near-expiry QR for this key, skip network
        if (lastKeyRef.current === key && expiresEpoch && (expiresEpoch - now()) > 2) {
            setQrAmount(qrAmount || cartTotal);
            return;
        }

        // Dedupe in-flight calls for same key
        if (inflightRef.current && lastKeyRef.current === key) {
            return;
        }

        // Abort any older request when key changes
        try { if (abortRef.current) { abortRef.current.abort(); } } catch (e) {}

        const controller = new AbortController();
        abortRef.current = controller;
        inflightRef.current = true;
        lastKeyRef.current = key;

        const doFetch = async () => {
            try {
                const resp = await fetch(settings.rest_url + '/qr-proxy', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': settings.nonce,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        session_token: sessionToken,
                        order_id: 0,
                        order_total: cartTotal,
                    }),
                    signal: controller.signal,
                });
                if (!resp.ok) return;
                const data = await resp.json();
                if (data && data.qr_url) {
                    setQrUrl(data.qr_url);
                }
                if (data && typeof data.amount !== 'undefined') {
                    setQrAmount(Number(data.amount) || cartTotal);
                } else {
                    setQrAmount(cartTotal);
                }
                if (data && typeof data.ref_code !== 'undefined') {
                    setRefCode(String(data.ref_code || ''));
                }
                if (data && typeof data.expires_epoch !== 'undefined' && data.expires_epoch) {
                    setExpiresEpoch(parseInt(data.expires_epoch, 10));
                } else {
                    setExpiresEpoch(null);
                }
            } catch (e) {
                // leave placeholder on failure
                setQrAmount(cartTotal);
            } finally {
                inflightRef.current = false;
            }
        };
        doFetch();
        return () => controller.abort();
    }, [cartTotal, sessionToken]);

    // Countdown updater for expiry
    useEffect(() => {
        if (!expiresEpoch) {
            setCountdown('');
            return;
        }
        const format = (secs) => {
            const s = Math.max(0, Math.floor(secs));
            const m = Math.floor(s / 60);
            const r = s % 60;
            return `${m}:${r.toString().padStart(2, '0')}`;
        };
        const id = setInterval(() => {
            const remain = parseInt(expiresEpoch, 10) - Math.floor(Date.now() / 1000);
            if (remain <= 0) {
                setCountdown('0:00');
                clearInterval(id);
            } else {
                setCountdown(format(remain));
            }
        }, 1000);
        return () => clearInterval(id);
    }, [expiresEpoch]);

    useEffect(() => {
        // Handle payment setup
        const unsubscribe = onPaymentSetup(() => {
            if (verificationStatus !== 'approved') {
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: settings.i18n.verify_required,
                };
            }

            return {
                type: emitResponse.responseTypes.SUCCESS,
                meta: {
                    paymentMethodData: {
                        san8n_approval_status: verificationStatus,
                        san8n_reference_id: referenceId,
                        san8n_approved_amount: approvedAmount,
                        san8n_session_token: sessionToken,
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
                    message: settings.i18n.verify_required,
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
        formData.append('session_token', sessionToken);
        formData.append('order_id', 0);
        formData.append('order_total', cartTotal);

        try {
            const controller = new AbortController();
            const timeoutMs = parseInt((settings.settings && settings.settings.verify_timeout_ms) || 9000, 10);
            const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

            const response = await fetch(settings.rest_url + '/verify-slip', {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': settings.nonce,
                },
                credentials: 'same-origin',
                body: formData,
                signal: controller.signal,
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
            if (error && error.name === 'AbortError') {
                setStatusMessage(settings.i18n.timeout || settings.i18n.error);
            } else {
                setStatusMessage(settings.i18n.error);
            }
        } finally {
            try { if (typeof timeoutId !== 'undefined') { clearTimeout(timeoutId); } } catch (e) {}
            setIsVerifying(false);
        }
    }, [slipFile, cartTotal, sessionToken]);

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
            // Defensive: backend should never return other statuses in checkout-only flow
            setVerificationStatus('error');
            setStatusMessage(settings.i18n.error);
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
                        src={qrUrl}
                        alt="QR Code"
                        className="san8n-qr-placeholder"
                    />
                    <div className="san8n-amount-display">
                        {settings.i18n.amount_label.replace('%s', (qrAmount || cartTotal).toFixed(2))}
                    </div>
                    {refCode ? (
                        <div className="san8n-ref-code">
                            {settings.i18n.ref_code_label ? settings.i18n.ref_code_label.replace('%s', String(refCode)) : `Reference: ${String(refCode)}`}
                        </div>
                    ) : null}
                    {countdown ? (
                        <div className="san8n-qr-expiry">
                            {settings.i18n.qr_expires_in ? settings.i18n.qr_expires_in.replace('%s', countdown) : `QR expires in ${countdown}`}
                        </div>
                    ) : null}
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
