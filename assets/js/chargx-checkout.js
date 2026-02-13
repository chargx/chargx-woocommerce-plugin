(function ($) {
  "use strict";

  if (typeof chargx_wc_params === "undefined") {
    return;
  }

  var ChargXCardHandler = {
    processing: false,

    threeDSEnabled: false,
    threeDSMountSelector: null,
    gateway3DS: null,
    threeDS: null,
    threeDSUI: null,
    threeDSChallenged: false,
    paymentRedirectionFlow: false,
    paymentRedirectSuccessUrl: null,

    init: function () {
      // Hook into WooCommerce checkout JS lifecycle for the card gateway.
      $("form.checkout, form#order_review").on(
        "checkout_place_order_" + chargx_wc_params.card_gateway_id,
        ChargXCardHandler.onCheckoutPlaceOrder
      );

      $(document.body).on("checkout_error", function () {
        console.log("[XCardHandler] form checkout_error, cleanup");
        $("#chargx-opaque-data").val("");
      });

      function attachExpiryListener() {
        $("#chargx-card-expiry").on("input", function () {
          const formatted = ChargXCheckoutUtils.formatExpiryDate($(this).val());
          $(this).val(formatted);
        });
      }
      $("body").on("init_checkout", attachExpiryListener);
      $("body").on("updated_checkout", attachExpiryListener);

      function attachCardNumberListener() {
        $("#chargx-card-number").on("input", function () {
          const formatted = ChargXCheckoutUtils.formatCardNumber($(this).val());
          $(this).val(formatted);
        });
      }
      $("body").on("init_checkout", attachCardNumberListener);
      $("body").on("updated_checkout", attachCardNumberListener);

      console.log("[XCardHandler] init");

      ChargXCardHandler.threeDSEnabled =
        chargx_wc_params["enable_3ds"] === "yes";
      ChargXCardHandler.threeDSMountSelector =
        chargx_wc_params["3ds_mount_element_selector"];

      console.log(
        "[3DS] [XCardHandler] threeDSEnabled",
        ChargXCardHandler.threeDSEnabled,
        ChargXCardHandler.threeDSMountSelector
      );

      ChargXCardHandler.paymentRedirectionFlow =
        chargx_wc_params["payment_redirection_flow"];
      ChargXCardHandler.paymentRedirectSuccessUrl =
        chargx_wc_params["payment_redirect_success_url"];

      ChargXCardHandler.apiEndpoint = chargx_wc_params["api_endpoint"];

      console.log(
        "[ChargXCardHandler.apiEndpoint]",
        ChargXCardHandler.apiEndpoint
      );
    },

    getBillingAddress: function () {
      const v = (name) =>
        document.querySelector(`[name="${name}"]`)?.value?.trim() || "";

      return {
        first_name: v("billing_first_name"),
        last_name: v("billing_last_name"),
        company: v("billing_company"),
        address_1: v("billing_address_1"),
        address_2: v("billing_address_2"),
        city: v("billing_city"),
        state: v("billing_state"),
        postcode: v("billing_postcode"),
        country: v("billing_country"),
        phone: v("billing_phone"),
        email: v("billing_email"),
      };
    },

    onCheckoutPlaceOrder: function (e) {
      console.log("[XCardHandler] onCheckoutPlaceOrder", e);

      const opaqueData = $("#chargx-opaque-data").val();

      console.log(
        "[XCardHandler] onCheckoutPlaceOrder: processing",
        ChargXCardHandler.processing
      );
      console.log(
        "[XCardHandler] onCheckoutPlaceOrder opaqueData",
        !!opaqueData
      );

      console.log(
        "[XCardHandler] onCheckoutPlaceOrder: paymentRedirectionFlow",
        ChargXCardHandler.paymentRedirectionFlow
      );

      if (!ChargXCardHandler.processing && opaqueData) {
        // All data is here, continue with normal form submission to backend
        console.log("[XCardHandler] onCheckoutPlaceOrder SUBMIT!");
        return true; // true means do submit
      }

      if (ChargXCardHandler.threeDSChallenged) {
        // if 3DS is visible - skip submission
        console.log("[XCardHandler] threeDSChallenged, skip");
        return false;
      }

      // Payment redirection flow: create payment request and redirect to external checkout
      //
      if (ChargXCardHandler.paymentRedirectionFlow === "yes") {
        form.trigger("submit");
        return;
      }
      //
      //

      e.preventDefault();

      var form = $(this);

      // Basic validation.
      var cardNumber = $("[data-chargx-card-number]").val().replace(/\s+/g, "");
      var exp = $("[data-chargx-card-expiry]").val().replace(/\s+/g, "");
      var cvc = $("[data-chargx-card-cvc]").val().trim();

      if (!cardNumber || !exp || !cvc) {
        window.wc_checkout_form &&
          window.wc_checkout_form.submit_error(
            '<ul class="woocommerce-error"><li>' +
              chargx_wc_params.i18n.card_required +
              "</li></ul>"
          );
        return false;
      }

      var expParts = exp.split("/");
      if (expParts.length !== 2) {
        window.wc_checkout_form &&
          window.wc_checkout_form.submit_error(
            '<ul class="woocommerce-error"><li>' +
              chargx_wc_params.i18n.card_required +
              "</li></ul>"
          );
        return false;
      }

      var month = expParts[0].trim();
      var year = expParts[1].trim();
      if (year.length === 2) {
        // Convert YY to YYYY (simple heuristic, not perfect).
        var now = new Date().getFullYear();
        var base = Math.floor(now / 100) * 100;
        year = base + parseInt(year, 10);
      }

      ChargXCardHandler.processing = true;

      ChargXCardHandler.tokenizeCard(cardNumber, month, year, cvc)
        .then(function (opaqueData) {
          console.log(
            "[3DS] tokenizeCard done, threeDSEnabled",
            ChargXCardHandler.threeDSEnabled
          );

          $("#chargx-opaque-data").val(JSON.stringify(opaqueData));

          // show 3DS UI
          if (ChargXCardHandler.threeDSEnabled) {
            const cartTotal = chargx_wc_params.cart_total || 0;
            const amount = cartTotal.toFixed
              ? cartTotal.toFixed(2)
              : String(cartTotal);

            const billing = ChargXCardHandler.getBillingAddress();
            console.log("[3DS] billing", billing);

            const options = {
              paymentToken: opaqueData.token,
              currency: chargx_wc_params.currency,
              amount,
              firstName: billing.first_name,
              lastName: billing.last_name,
              email: billing.email,
              address1: billing.address_1,
              city: billing.city,
              state: billing.state,
              country: billing.country,
              postalCode: billing.postcode,
              phone: billing.phone,
            };
            ChargXCardHandler.threeDSUI =
              ChargXCardHandler.threeDS.createUI(options);

            console.log("[3DS] start", ChargXCardHandler.threeDSMountSelector);

            ChargXCardHandler.threeDSUI.start(
              ChargXCardHandler.threeDSMountSelector
            );

            // listen for 3ds callbacks
            // Listen for the 'challenge' callback to ask the user for a password
            ChargXCardHandler.threeDSUI.on("challenge", (e) => {
              console.log("[3DS] Challenged", e);
              ChargXCardHandler.threeDSChallenged = true;
            });
            // Listen for the 'complete' callback to provide all the needed 3DS data
            ChargXCardHandler.threeDSUI.on("complete", (e) => {
              console.log("[3DS] complete", e);

              const threeDSData = {
                cavv: e.cavv,
                xid: e.xid,
                eci: e.eci,
                cardHolderAuth: e.cardHolderAuth,
                threeDsVersion: e.threeDsVersion,
                directoryServerId: e.directoryServerId,
              };
              $("#chargx-3ds-data").val(JSON.stringify(threeDSData));

              // Now submit the form "for real".
              ChargXCardHandler.processing = false;
              ChargXCardHandler.threeDSChallenged = false;
              form.trigger("submit"); // triggers WC checkout again, but now with flag above.
            });
            ChargXCardHandler.threeDSUI.on("error", (e) => {
              ChargXCardHandler.processing = false;
              ChargXCardHandler.threeDSChallenged = false;

              console.error("[3DS] error", e);

              // unmount so a user can retry
              try {
                ChargXCardHandler.threeDSUI.unmount();
              } catch (e) {
                console.warn("threeDSUI.unmount error", e);
              }

              // TODO: maybe we should fail with error?
              //
              // The card does NOT participate in 3DS
              //
              // Now submit the form "for real".
              form.trigger("submit"); // triggers WC checkout again, but now with flag above.
            });
            // Listen for the 'failure' callback to indicate that the customer has failed to authenticate
            ChargXCardHandler.threeDSUI.on("failure", (e) => {
              ChargXCardHandler.processing = false;
              ChargXCardHandler.threeDSChallenged = false;

              console.error("[3DS] failure", e);

              // unmount so a user can retry
              try {
                ChargXCardHandler.threeDSUI.unmount();
              } catch (e) {
                console.warn("threeDSUI.unmount error", e);
              }

              if (e.code === "TRANSACTION_STATUS_U") {
                if (
                  e.cardHolderInfo?.toLowerCase() ===
                  "challenge cancelled by user"
                ) {
                  // User clicked on Cancel button on challenge -> fail
                  $("#chargx-opaque-data").val(""); // reset opaqueData

                  alert(
                    "Verification was cancelled. Your payment wasn't completed. Please try again"
                  );
                } else {
                  // Authentication unavailable / not enrolled
                  // The card does NOT participate in 3DS
                  // → ✅ Proceed with payment WITHOUT liability shift

                  // Now submit the form "for real".
                  form.trigger("submit"); // triggers WC checkout again, but now with flag above.
                }
              } else if (
                e.code === "TRANSACTION_STATUS_N" ||
                e.code === "TRANSACTION_STATUS_R"
              ) {
                // hard stop, show error message
                // R — Rejected
                // Issuer rejected authentication
                // High fraud signal
                // ❌ Do not fall back
                // N — Not authenticated / Failed
                // Authentication attempted but failed
                // ❌ Do not fall back

                $("#chargx-opaque-data").val(""); // reset opaqueData

                alert(
                  "Error while doing 3DS verification, Issuer rejected authentication: " +
                    e.message
                );
              } else {
                // Unknown Error
                // Timeout Error
                // Error on Authentication

                $("#chargx-opaque-data").val(""); // reset opaqueData

                alert("Error while doing 3DS verification: " + e.message);
              }
            });
            // Listen for any errors that might occur
            ChargXCardHandler.gateway3DS.on("error", (e) => {
              console.error("[3DS] gateway general error", e);

              $("#chargx-opaque-data").val(""); // reset opaqueData
              ChargXCardHandler.processing = false;
              ChargXCardHandler.threeDSChallenged = false;
            });
          } else {
            // Now submit the form "for real".
            ChargXCardHandler.processing = false;
            ChargXCardHandler.threeDSChallenged = false;
            form.trigger("submit"); // triggers WC checkout again, but now with flag above.
          }
        })
        .catch(function (error) {
          ChargXCardHandler.processing = false;
          ChargXCardHandler.threeDSChallenged = false;

          const msg =
            error && error.message
              ? error.message
              : chargx_wc_params.i18n.card_error;
          window.wc_checkout_form &&
            window.wc_checkout_form.submit_error(
              '<ul class="woocommerce-error"><li>' + msg + "</li></ul>"
            );
        });

      return false;
    },

    tokenizeCard: function (cardNumber, month, year, cvc) {
      return new Promise(function (resolve, reject) {
        var publishable = chargx_wc_params.card_publishable;
        if (!publishable) {
          return reject(
            new Error("ChargX publishable API key is not configured.")
          );
        }

        function replaceHostIfPort9000(url) {
          return url.replace(/^https?:\/\/[^/]+:9000$/, (m) =>
            m.replace(/\/\/[^/]+:9000$/, "//localhost:9000")
          );
        }
        // Step 1: pretransact to get cardTokenRequestUrl and params.
        fetch(`${replaceHostIfPort9000(ChargXCardHandler.apiEndpoint)}/pretransact`, {
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
            if (
              !data ||
              !data.cardTokenRequestUrl ||
              !data.cardTokenRequestParams
            ) {
              throw new Error("Invalid pretransact response from ChargX.");
            }

            // enable 3DS
            if (ChargXCardHandler.threeDSEnabled) {
              console.log("[3DS] enable");
              if (!ChargXCardHandler.gateway3DS) {
                ChargXCardHandler.gateway3DS = Gateway.create(
                  data.gatewayPublicKey
                );
              }
              if (!ChargXCardHandler.threeDS) {
                ChargXCardHandler.threeDS =
                  ChargXCardHandler.gateway3DS.get3DSecure();
              }
            }

            var tokenUrl = data.cardTokenRequestUrl;
            var tokenParams = data.cardTokenRequestParams;

            const expirationDate =
              ((month + "").length === 1 ? "0" + month : month) +
              (year + "").slice(-2);

            // Replace stubs.
            var paramsStr = JSON.stringify(tokenParams)
              .replace(/#cardNumber#/g, cardNumber)
              .replace(/#expirationDate#/g, expirationDate)
              .replace(/#cardCode#/g, cvc);

            return fetch(tokenUrl, {
              method: "POST",
              headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
              },
              body: paramsStr,
            });
          })
          .then(function (res) {
            return res.json();
          })
          .then(function (data) {
            if (!data || !(data.opaqueData || data.token)) {
              throw new Error(
                "Invalid card tokenization response from processor."
              );
            }
            resolve(data.opaqueData || { token: data.token });
          })
          .catch(function (err) {
            reject(err);
          });
      });
    },
  };

  $(function () {
    ChargXCardHandler.init();
    ChargXApplePayHandler.init();
    ChargXGooglePayHandler.init();
  });
})(jQuery);
