/* global jQuery, paypal */
// PayPal integration for AI Product Importer Pro.
//
// This script renders a "Buy Credits" section in the admin UI using the
// PayPal JavaScript SDK. Packs and pricing are defined by the backend and
// passed to the page via the AIPI_PRO_PAYPAL global. The browser does not
// construct PayPal orders directly; it calls a WordPress AJAX endpoint
// that in turn asks the managed backend to create an order. After the
// buyer approves and captures the payment, the plugin shows a pending
// confirmation message and polls the ledger for a balance change. The
// installation ID is no longer sent in the order; the backend infers
// identity from the installation token included on the server side.
//
// Prerequisites:
// - The PayPal SDK is enqueued before this script (see Plugin::enqueue_assets).
// - The AIPI_PRO and AIPI_PRO_PAYPAL objects are localized by PHP. The
//   latter includes a `packs` array (preferred) or a `packAmounts` array.
(function ($) {
    function showNotice(message, type) {
        const notice = $('#aipi-pro-notice');
        if (!notice.length) {
            window.alert(String(message));
            return;
        }
        notice.stop(true, true)
            .removeClass('notice-success notice-error notice-info')
            .addClass('notice-' + (type || 'info'))
            .text(String(message))
            .show();
    }

    $(function () {
        let initialized = false;

        const initWhenReady = function (attempt) {
            if (initialized) {
                return;
            }

            if (typeof paypal === 'undefined' || !paypal.Buttons) {
                if (attempt < 20) {
                    window.setTimeout(function () {
                        initWhenReady(attempt + 1);
                    }, 500);
                    return;
                }
                console.warn('PayPal SDK not loaded; Buy Credits UI will not render.');
                return;
            }

            const appConfig = window.AIPI_PRO || null;
            const paypalConfig = window.AIPI_PRO_PAYPAL || null;
            // Prefer the structured packs array provided by the backend. Each pack
            // is expected to have an `id` and `amount` field. Fallback to
            // synthesising packs from packAmounts when present.
            let packs = [];
            if (paypalConfig && Array.isArray(paypalConfig.packs)) {
                packs = paypalConfig.packs.map(function (p) {
                    if (typeof p === 'object' && p && typeof p.amount !== 'undefined') {
                        return {
                            id: typeof p.id === 'string' ? p.id : (typeof p.key === 'string' ? p.key : ''),
                            amount: Number(p.amount),
                            label: typeof p.label === 'string' ? p.label : '',
                        };
                    }
                    return null;
                }).filter(function (p) { return p && p.amount > 0; });
            } else if (paypalConfig && Array.isArray(paypalConfig.packAmounts)) {
                packs = paypalConfig.packAmounts.map(function (amt, idx) {
                    const n = Number(amt);
                    return isFinite(n) && n > 0 ? { id: 'pack_' + idx, amount: n, label: '' } : null;
                }).filter(function (p) { return p; });
            }
            const currency = paypalConfig && typeof paypalConfig.currency === 'string' && /^[A-Z]{3}$/.test(paypalConfig.currency) ? paypalConfig.currency : 'USD';

            const formatAmountLabel = function (amountValue) {
                const safeAmount = Number(amountValue);
                const safeCurrency = currency || 'USD';
                try {
                    return new Intl.NumberFormat(undefined, {
                        style: 'currency',
                        currency: safeCurrency,
                    }).format(safeAmount);
                } catch (e) {
                    return safeCurrency + ' ' + safeAmount.toFixed(2);
                }
            };

            if (!appConfig || !paypalConfig || packs.length === 0) {
                if (attempt < 20) {
                    window.setTimeout(function () {
                        initWhenReady(attempt + 1);
                    }, 500);
                    return;
                }
                console.warn('PayPal config missing; Buy Credits UI will not render.');
                return;
            }

            const container = $('#aipi-pro-buy-credits');
            if (container.length === 0) {
                return;
            }
            initialized = true;
            let currentKnownBalance = null;
            const fetchBalance = function () {
                const runtimeConfig = window.AIPI_PRO || null;
                if (!(runtimeConfig && runtimeConfig.ajaxUrl && runtimeConfig.nonce)) {
                    return $.Deferred().reject().promise();
                }
                return $.post(
                    runtimeConfig.ajaxUrl,
                    {
                        action: 'aipi_get_balance',
                        nonce: runtimeConfig.nonce,
                    }
                ).then(function (response) {
                    if (response && response.success && response.data) {
                        const balance = response.data.balance ?? response.data.credits ?? response.data.available_credits;
                        if (balance !== undefined && balance !== null && balance !== '') {
                            currentKnownBalance = String(balance);
                            return currentKnownBalance;
                        }
                    }
                    return null;
                });
            };
            fetchBalance();
            // Build the Buy Credits UI.
            container.addClass('aipi-buy-credits');
            container.append('<h2>Buy Credits</h2>');
            // For each pack, render a PayPal button. Each pack is an object with
            // an `id`, an `amount`, and an optional `label`. If no label is
            // provided, one is generated using the currency.
            packs.forEach(function (pack, index) {
                const amt = Number(pack.amount);
                if (!isFinite(amt) || amt <= 0) {
                    return;
                }
                const packContainerId = 'paypal-pack-' + index;
                const wrapper = $('<div class="aipi-paypal-pack" style="margin-bottom:8px;"></div>');
                // Generate label from pack.label or fallback to currency format.
                const labelText = pack.label && pack.label.length ? pack.label : formatAmountLabel(amt);
                $('<p>', {
                    class: 'aipi-paypal-pack-label',
                    text: labelText
                }).appendTo(wrapper);
                wrapper.append('<div class="paypal-button-container" id="' + packContainerId + '"></div>');
                container.append(wrapper);
                paypal.Buttons({
                    style: {
                        layout: 'horizontal',
                        color: 'gold',
                        shape: 'rect',
                        label: 'paypal',
                        height: 40,
                    },
                    // Delegate order creation to the server using the configured
                    // credit pack identifier. The server validates the pack and
                    // constructs the order.
                    createOrder: function () {
                        const runtimeConfig = window.AIPI_PRO || null;
                        if (!(runtimeConfig && runtimeConfig.ajaxUrl && runtimeConfig.nonce)) {
                            console.error('Runtime config unavailable; cannot create PayPal order');
                            return Promise.reject(new Error('Runtime config unavailable'));
                        }
                        const payload = {
                            action: 'aipi_create_paypal_order',
                            nonce: runtimeConfig.nonce,
                        };
                        if (pack.id) {
                            payload.pack_id = pack.id;
                        }
                        return $.post(runtimeConfig.ajaxUrl, payload).then(
                            function (response) {
                                if (response && response.success && response.data && response.data.paypal_order_id) {
                                    return response.data.paypal_order_id;
                                }
                                const msg = (response && response.data && response.data.message) || (response && response.message) || 'Order creation failed.';
                                showNotice(msg, 'error');
                                throw new Error(msg);
                            },
                            function (xhr) {
                                console.error(xhr);
                                showNotice('An error occurred creating the order.', 'error');
                                throw new Error('Order creation failed');
                            }
                        );
                    },
                    // After the buyer approves the payment, capture the order. The PayPal
                    // SDK performs the capture call. When capture resolves,
                    // inform the user and begin polling for confirmation.
                    onApprove: function (data, actions) {
                        return actions.order.capture().then(function () {
                            showNotice('Payment submitted. Waiting for confirmation.', 'success');
                            const prePaymentBalance = currentKnownBalance;
                            const runtimeConfig = window.AIPI_PRO || null;
                            if (runtimeConfig && runtimeConfig.ajaxUrl && runtimeConfig.nonce) {
                                let attempts = 0;
                                const maxAttempts = 6;
                                const pollBalance = function () {
                                    attempts += 1;
                                    fetchBalance().done(function (balance) {
                                        if (balance !== null) {
                                            if (prePaymentBalance === null || balance !== prePaymentBalance) {
                                                showNotice('Payment confirmed. Current balance: ' + balance, 'success');
                                                return;
                                            }
                                        }
                                        if (attempts < maxAttempts) {
                                            setTimeout(pollBalance, attempts * 4000);
                                        }
                                    }).fail(function () {
                                        if (attempts < maxAttempts) {
                                            setTimeout(pollBalance, attempts * 4000);
                                        }
                                    });
                                };
                                setTimeout(pollBalance, 5000);
                            }
                        });
                    },
                    onError: function (err) {
                        console.error(err);
                        showNotice('An error occurred during payment: ' + (err && err.message ? err.message : 'Unknown error'), 'error');
                    },
                }).render('#' + packContainerId);
            });
        };

        initWhenReady(0);
    });
})(jQuery);