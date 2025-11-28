(function ($) {
    'use strict';

    if (typeof chargx_wc_params === 'undefined') {
        return;
    }

    var ChargXCardHandler = {
        processing: false,

        init: function () {
            // Hook into WooCommerce checkout JS lifecycle for the card gateway.
            $('form.checkout, form#order_review').on(
                'checkout_place_order_' + chargx_wc_params.card_gateway_id,
                ChargXCardHandler.onCheckoutPlaceOrder
            );
        },

        onCheckoutPlaceOrder: function (e) {
            if (ChargXCardHandler.processing) {
                // Already tokenized; allow submit.
                return true;
            }
          
            const opaqueData = $("#chargx-opaque-data").val(); 
            if (opaqueData) {
              return;
            }
            
            e.preventDefault();

            var form = $(this);

            // Basic validation.
            var cardNumber = $('[data-chargx-card-number]').val().replace(/\s+/g, '');
            var exp        = $('[data-chargx-card-expiry]').val().replace(/\s+/g, '');
            var cvc        = $('[data-chargx-card-cvc]').val().trim();

            if (!cardNumber || !exp || !cvc) {
                window.wc_checkout_form && window.wc_checkout_form.submit_error('<ul class="woocommerce-error"><li>' + chargx_wc_params.i18n.card_required + '</li></ul>');
                return false;
            }

            var expParts = exp.split('/');
            if (expParts.length !== 2) {
                window.wc_checkout_form && window.wc_checkout_form.submit_error('<ul class="woocommerce-error"><li>' + chargx_wc_params.i18n.card_required + '</li></ul>');
                return false;
            }

            var month = expParts[0].trim();
            var year  = expParts[1].trim();
            if (year.length === 2) {
                // Convert YY to YYYY (simple heuristic, not perfect).
                var now  = new Date().getFullYear();
                var base = Math.floor(now / 100) * 100;
                year     = base + parseInt(year, 10);
            }

            ChargXCardHandler.processing = true;

            ChargXCardHandler.tokenizeCard(cardNumber, month, year, cvc)
                .then(function (opaqueData) {
                    $('#chargx-opaque-data').val(JSON.stringify(opaqueData));

                    // Now submit the form "for real".
                    ChargXCardHandler.processing = false;
                    form.trigger('submit'); // triggers WC checkout again, but now with flag above.
                })
                .catch(function (error) {
                    ChargXCardHandler.processing = false;
                    var msg = error && error.message ? error.message : chargx_wc_params.i18n.card_error;
                    window.wc_checkout_form && window.wc_checkout_form.submit_error('<ul class="woocommerce-error"><li>' + msg + '</li></ul>');
                });

            return false;
        },

        tokenizeCard: function (cardNumber, month, year, cvc) {
            return new Promise(function (resolve, reject) {
                var publishable = chargx_wc_params.card_publishable;
                if (!publishable) {
                    return reject(new Error('ChargX publishable API key is not configured.'));
                }

                // Step 1: pretransact to get cardTokenRequestUrl and params.
                fetch('https://api.chargx.io/pretransact', {
                    method: 'GET',
                    headers: {
                        'x-publishable-api-key': publishable,
                        'Accept': 'application/json'
                    }
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (!data || !data.cardTokenRequestUrl || !data.cardTokenRequestParams) {
                            throw new Error('Invalid pretransact response from ChargX.');
                        }

                      var tokenUrl    = data.cardTokenRequestUrl;
                      var tokenParams = data.cardTokenRequestParams;
                      
                      const expirationDate = ((month + "").length === 1 ? "0" + month : month) + (year + "").slice(-2);

                        // Replace stubs.
                        var paramsStr = JSON.stringify(tokenParams)
                          .replace(/#cardNumber#/g, cardNumber)
                          .replace(/#expirationDate#/g, expirationDate)
                          .replace(/#cardCode#/g, cvc);

                        return fetch(tokenUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json'
                            },
                            body: paramsStr
                        });
                    })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (!data || !data.opaqueData) {
                            throw new Error('Invalid card tokenization response from processor.');
                        }
                        resolve(data.opaqueData);
                    })
                    .catch(function (err) {
                        reject(err);
                    });
            });
        }
    };

    var ChargXApplePayHandler = {
        initialized: false,

        init: function () {
            if (ChargXApplePayHandler.initialized) {
                return;
            }

            ChargXApplePayHandler.initialized = true;

            $(document.body).on('click', '#chargx-applepay-button', ChargXApplePayHandler.onApplePayClick);

            // Hide Apple Pay container if ApplePaySession not available.
            if (!window.ApplePaySession || !window.ApplePaySession.canMakePayments()) {
                $('#payment_method_' + chargx_wc_params.apple_gateway_id).hide();
            }
        },

        onApplePayClick: function (e) {
            e.preventDefault();

            if (!window.ApplePaySession || !window.ApplePaySession.canMakePayments()) {
                alert(chargx_wc_params.i18n.apple_not_avail);
                return false;
            }

            var publishable = chargx_wc_params.apple_publishable;
            if (!publishable) {
                alert('Apple Pay publishable key is missing.');
                return false;
            }

            // Step 1: pretransact to grab applePay.paymentRequest.
            fetch('https://api.chargx.io/pretransact', {
                method: 'GET',
                headers: {
                    'x-publishable-api-key': publishable,
                    'Accept': 'application/json'
                }
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || !data.applePay || !data.applePay.paymentRequest) {
                        throw new Error('Apple Pay configuration not available from ChargX.');
                    }

                    var pr = data.applePay.paymentRequest;

                    // Set total amount from cart/order.
                    var amount = chargx_wc_params.cart_total || 0;
                    pr.total = pr.total || {};
                    pr.total.amount = amount.toFixed ? amount.toFixed(2) : String(amount);

                    ChargXApplePayHandler.startApplePaySession(pr);
                })
                .catch(function (err) {
                    console.error('Apple Pay pretransact error:', err);
                    alert(chargx_wc_params.i18n.apple_error);
                });

            return false;
        },

        startApplePaySession: function (paymentRequest) {
            var applePayVersion = 3;
            var session = new window.ApplePaySession(applePayVersion, paymentRequest);

            // Merchant validation.
            session.onvalidatemerchant = function (event) {
                $.ajax({
                    url: chargx_wc_params.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'chargx_applepay_validate_merchant',
                        validationUrl: event.validationURL
                    }
                })
                    .done(function (response) {
                        if (!response || !response.success) {
                            console.error('Apple Pay merchant validation failed:', response);
                            session.abort();
                            return;
                        }
                        session.completeMerchantValidation(response.data);
                    })
                    .fail(function (xhr, status, error) {
                        console.error('Apple Pay merchant validation error:', error);
                        session.abort();
                    });
            };

            // Payment authorized.
            session.onpaymentauthorized = function (event) {
                var payment      = event.payment;
                var tokenObject  = payment.token;
                var paymentData  = tokenObject && tokenObject.paymentData ? tokenObject.paymentData : null;

                if (!paymentData) {
                    session.completePayment(window.ApplePaySession.STATUS_FAILURE);
                    alert(chargx_wc_params.i18n.apple_error);
                    return;
                }

                var tokenBase64 = btoa(JSON.stringify(paymentData));
                $('#chargx-applepay-token').val(tokenBase64);

                // Submit checkout via AJAX to WooCommerce.
                var $form = $('form.checkout');
                if (!$form.length) {
                    // fallback to classic submission
                    session.completePayment(window.ApplePaySession.STATUS_FAILURE);
                    alert(chargx_wc_params.i18n.apple_error);
                    return;
                }

                var data = $form.serialize();

                $.ajax({
                    type: 'POST',
                    url: chargx_wc_params.checkout_url,
                    data: data,
                    dataType: 'json',
                    success: function (result) {
                        if (result && result.result === 'success') {
                            session.completePayment(window.ApplePaySession.STATUS_SUCCESS);

                            if (result.redirect) {
                                window.location = result.redirect;
                            } else {
                                window.location.reload();
                            }
                        } else {
                            session.completePayment(window.ApplePaySession.STATUS_FAILURE);

                            var message = chargx_wc_params.i18n.apple_error;
                            if (result && result.messages) {
                                message = result.messages;
                            }

                            window.wc_checkout_form && window.wc_checkout_form.submit_error(message);
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('Apple Pay checkout ajax error:', error);
                        session.completePayment(window.ApplePaySession.STATUS_FAILURE);
                        window.wc_checkout_form && window.wc_checkout_form.submit_error('<ul class="woocommerce-error"><li>' + chargx_wc_params.i18n.apple_error + '</li></ul>');
                    }
                });
            };

            session.oncancel = function () {
                // User canceled Apple Pay.
            };

            session.begin();
        }
    };

    $(function () {
        ChargXCardHandler.init();
        ChargXApplePayHandler.init();
    });

})(jQuery);
