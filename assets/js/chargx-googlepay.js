(function ($) {
  "use strict";

  if (typeof chargx_wc_params === "undefined") {
    return;
  }

  window.ChargXGooglePayHandler = {
    initialized: false,
    paymentRequest: null,

    init: function () {
      if (window.ChargXGooglePayHandler.initialized) {
        return;
      }

      window.ChargXGooglePayHandler.initialized = true;

      $(document.body).on(
        "click",
        "#chargx-googlepay-button",
        window.ChargXGooglePayHandler.onGooglePayClick
      );

      // Hide Google Pay container if PaymentRequest not available.
      if (!window.PaymentRequest) {
        $("#payment_method_" + chargx_wc_params.google_gateway_id).hide();
      }
    },

    onGooglePayClick: async function (e) {
      e.preventDefault();

      const publishable = chargx_wc_params.google_publishable;
      if (!publishable) {
        alert("Google Pay publishable key is missing.");
        return false;
      }

      // Step 1: pretransact to grab googlePay.methodData.
      //
      let data;
      try {
        data = await fetch("https://api.chargx.io/pretransact", {
          method: "GET",
          headers: {
            "x-publishable-api-key": publishable,
            Accept: "application/json",
          },
        })
          .then(function (res) {
            return res.json();
          })
          .then(function (data) {
            return data;
          });
      } catch (e) {
        console.error(
          "Google Pay: error retrieving configuration from ChargX",
          e
        );
        alert(chargx_wc_params.i18n.google_error);
        return false;
      }
      if (!data || !data.googlePay) {
        console.error(
          "Google Pay configuration not available from ChargX.",
          data
        );
        alert(chargx_wc_params.i18n.google_error);
        return false;
      }

      const googlePayMethodData = data.googlePay.methodData;

      // Step 2: create google PaymentRequest
      //
      const amount = chargx_wc_params.cart_total || 0;
      const amountParsed = amount.toFixed ? amount.toFixed(2) : String(amount);

      const details = {
        total: {
          label: "Total",
          amount: {
            currency: chargx_wc_params.currency,
            value: amountParsed,
          },
        },
      };

      let paymentRequest;
      try {
        paymentRequest = new PaymentRequest(googlePayMethodData, details);
        const canPay = await paymentRequest.canMakePayment();
        if (!canPay) {
          alert(chargx_wc_params.i18n.google_error);
          return false;
        }
      } catch (e) {
        console.error("Google Pay: creating PaymentRequest error", e);
        alert(chargx_wc_params.i18n.google_error);
        return false;
      }

      // show Google Pay UI
      const paymentRequestResponse = await paymentRequest.show();
      console.log("Google Pay payment response:", paymentRequestResponse);

      // https://developers.google.com/pay/api/web/reference/response-objects#PaymentMethodData
      // That can be sent to supported payment processors.
      const paymentMethodData =
        paymentRequestResponse.details.paymentMethodData;
      //
      // An envelope containing a signed, encrypted payload, should be sent to PG to decrypt and process it
      const encryptedPaymentToken = paymentMethodData.tokenizationData.token;
      // console.log("[Google Pay] encryptedPaymentToken:", encryptedPaymentToken);

      // Step 3: Submit checkout via AJAX to WooCommerce.
      //
      const tokenBase64 = btoa(encryptedPaymentToken);
      $("#chargx-googlepay-token").val(tokenBase64);

      const $form = $("form.checkout");
      if (!$form.length) {
        // fallback to classic submission
        await paymentRequestResponse.complete("fail");
        alert(chargx_wc_params.i18n.google_error);
        return false;
      }

      const formData = $form.serialize();

      $.ajax({
        type: "POST",
        url: chargx_wc_params.checkout_url,
        data: formData,
        dataType: "json",
        success: async function (result) {
          if (result && result.result === "success") {
            await paymentRequestResponse.complete("success");

            if (result.redirect) {
              window.location = result.redirect;
            } else {
              window.location.reload();
            }
          } else {
            await paymentRequestResponse.complete("fail");

            let message = chargx_wc_params.i18n.google_error;
            if (result && result.messages) {
              message = result.messages;
            }

            window.wc_checkout_form &&
              window.wc_checkout_form.submit_error(message);
          }
        },
        error: async function (xhr, status, error) {
          console.error("Google Pay checkout ajax error:", error);
          await paymentRequestResponse.complete("fail");

          window.wc_checkout_form &&
            window.wc_checkout_form.submit_error(
              '<ul class="woocommerce-error"><li>' +
                chargx_wc_params.i18n.google_error +
                "</li></ul>"
            );
        },
      });
    },
  };
})(jQuery);
