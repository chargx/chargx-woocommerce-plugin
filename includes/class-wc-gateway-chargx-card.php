<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ChargX Card gateway.
 */
class WC_Gateway_ChargX_Card extends WC_Gateway_ChargX_Base {

    /**
     * Prevents rendering the confirmation popup more than once per request.
     *
     * @var bool
     */
    private static $popup_rendered = false;

    public function __construct() {
        $this->id                 = 'chargx_card';
        $this->method_title       = __( 'ChargX – Credit Card', 'chargx-woocommerce' );
        $this->method_description = __( 'Pay securely with credit/debit cards via ChargX.', 'chargx-woocommerce' );
        $this->has_fields         = true;
        parent::__construct();

        add_action('woocommerce_api_wc_gateway_chargx_card_success_url', [$this, 'handle_return']);
        add_action('woocommerce_api_wc_gateway_chargx_card_success_url_webhook', [$this, 'handle_webhook_success_payment']);
        add_action('woocommerce_api_chargx_order_status', [$this, 'ajax_order_status']);

        add_action( 'woocommerce_before_thankyou', array( $this, 'show_order_received_popup' ), 10, 1 );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options'] );
    }

    public function process_admin_options() {
        $this->log( 'process_admin_options', 'info' );

        parent::process_admin_options();

        $secret_key = $this->get_option('secret_key');
        $test_secret_key = $this->get_option('test_secret_key');

        if (!$secret_key && !$test_secret_key) {
            $this->log( 'process_admin_options ignore because no secret key defined', 'info' );
            return;
        }

        $this->ensure_webhook( false );
    }

    /**
     * Register the ChargX webhook and persist the one-time signing secret.
     *
     * Existing endpoints created by older plugin versions have no stored secret,
     * so we rotate the secret and keep the new value locally.
     *
     * @param bool $force When true (settings save), rotate if the endpoint already exists.
     * @return bool
     */
    public function ensure_webhook( $force = false ) {
        $secret_key      = $this->get_option( 'secret_key' );
        $test_secret_key = $this->get_option( 'test_secret_key' );
        if ( ! $secret_key && ! $test_secret_key ) {
            return false;
        }

        $mode        = ( 'yes' === $this->testmode ) ? 'test' : 'live';
        $webhook_url = ChargX_Webhook::webhook_url();

        if ( ! $force && ChargX_Webhook::is_configured_for( $mode, $webhook_url ) ) {
            return true;
        }

        $this->log( 'ensure_webhook mode=' . $mode, 'info' );

        $api      = $this->get_api_client();
        $existing = $api->get_webhooks();
        if ( is_wp_error( $existing ) ) {
            $this->log( 'ensure_webhook get_webhooks error: ' . $existing->get_error_message(), 'error' );
            return false;
        }

        $endpoints = isset( $existing['webhook_endpoints'] ) && is_array( $existing['webhook_endpoints'] )
            ? $existing['webhook_endpoints']
            : array();

        $match = null;
        foreach ( $endpoints as $endpoint ) {
            $ep_url = isset( $endpoint['url'] ) ? $endpoint['url'] : '';
            if ( $ep_url === $webhook_url ) {
                $match = $endpoint;
                break;
            }
        }

        if ( $match && ! empty( $match['id'] ) ) {
            if ( ! $force && ChargX_Webhook::is_configured_for( $mode, $webhook_url ) ) {
                return true;
            }
            $rotated = $api->rotate_webhook_secret( $match['id'] );
            if ( is_wp_error( $rotated ) || empty( $rotated['secret'] ) ) {
                $message = is_wp_error( $rotated ) ? $rotated->get_error_message() : 'missing secret';
                $this->log( 'ensure_webhook rotate error: ' . $message, 'error' );
                return false;
            }
            ChargX_Webhook::save_for_mode( $mode, $match['id'], $rotated['secret'], $webhook_url );
            $this->log( 'ensure_webhook rotated secret for ' . $webhook_url, 'info' );
            return true;
        }

        $result = $api->create_webhook(
            $webhook_url,
            'WOO',
            array( 'payment.succeeded' ),
            true
        );
        if ( is_wp_error( $result ) ) {
            $this->log( 'ensure_webhook create error: ' . $result->get_error_message(), 'error' );
            return false;
        }

        $endpoint_id = isset( $result['webhook_endpoint']['id'] ) ? $result['webhook_endpoint']['id'] : '';
        $secret      = isset( $result['secret'] ) ? $result['secret'] : '';
        if ( ! $endpoint_id || ! $secret ) {
            $this->log( 'ensure_webhook create response missing id or secret', 'error' );
            return false;
        }

        ChargX_Webhook::save_for_mode( $mode, $endpoint_id, $secret, $webhook_url );
        $this->log( 'ensure_webhook created: ' . $webhook_url, 'info' );
        return true;
    }

    /**
     * Payment fields on checkout page.
     * NOTE: card inputs deliberately do NOT have name attributes, so card data is never posted to your server.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo '<p>' . wp_kses_post( $this->description ) . '</p>';
        }

        if ( 'yes' !== $this->payment_redirection_flow ) {
            ?>
        <fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-cc-form" class="wc-chargx-card-form wc-payment-form">
            <div class="chargx-card-row">
                <label for="chargx-card-number"><?php esc_html_e( 'Card Number', 'chargx-woocommerce' ); ?> <span class="required">*</span></label>
                <div class="chargx-card-number-wrapper">
                    <input id="chargx-card-number"
                           class="input-text chargx-card-number"
                           type="tel"
                           inputmode="numeric"
                           autocomplete="cc-number"
                           data-chargx-card-number="1"
                           placeholder="•••• •••• •••• ••••" />
                    <span class="chargx-card-brand"></span>
                </div>
            </div>

            <div class="chargx-card-row chargx-card-row--exp-cvc">
                <div class="chargx-card-expiry">
                    <label for="chargx-card-expiry"><?php esc_html_e( 'Expiry (MM/YY)', 'chargx-woocommerce' ); ?> <span class="required">*</span></label>
                    <input id="chargx-card-expiry"
                           class="input-text chargx-card-expiry"
                           type="tel"
                           inputmode="numeric"
                           autocomplete="cc-exp"
                           data-chargx-card-expiry="1"
                           placeholder="<?php esc_attr_e( 'MM/YY', 'chargx-woocommerce' ); ?>" />
                </div>

                <div class="chargx-card-cvc">
                    <label for="chargx-card-cvc"><?php esc_html_e( 'CVC', 'chargx-woocommerce' ); ?> <span class="required">*</span></label>
                    <input id="chargx-card-cvc"
                           class="input-text chargx-card-cvc"
                           type="tel"
                           inputmode="numeric"
                           autocomplete="cc-csc"
                           data-chargx-card-cvc="1"
                           placeholder="•••" />
                </div>
            </div>

            <input type="hidden" id="chargx-opaque-data" name="chargx_opaque_data" value="" />
            <input type="hidden" id="chargx-3ds-data" name="chargx_3ds_data" value="" />
        </fieldset>
        <?php
        }
    }


    /**
     * Process the payment: uses previously generated opaqueData from JS.
     *
     * @param int $order_id
     * @return array|void
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( 'Order not found.', 'chargx-woocommerce' ), 'error' );
            return;
        }

        // Payment redirection flow: create payment request and redirect to external checkout
        //
        if ( 'yes' === $this->payment_redirection_flow ) {
            // 
            $api = $this->get_api_client();
            $payment_redirect_success_url = ChargX_Webhook::success_url( $order );
            $this->log( 'payment_redirect_success_url: ' . $payment_redirect_success_url, 'info' );
            $response = $api->create_payment_request( $order->get_total(), $order->get_currency(), "card", $payment_redirect_success_url );
            if ( is_wp_error( $response ) ) {
                wc_add_notice( __( 'Payment has been failed..', 'chargx-woocommerce' ), 'error' );
                return;
            }
            $payment_request = $response['payment_request'];
            $checkout_url = $payment_request['checkout_url'] . '?success_url=' . urlencode($payment_redirect_success_url);

            // Pass billing params to payment form (from checkout form / order).
            $billing_params = array_filter(array(
                'email'          => $order->get_billing_email(),
                'phone-number'   => $order->get_billing_phone(),
                'street-address' => $order->get_billing_address_1(),
                'unit-address'   => $order->get_billing_address_2(),
                'city'           => $order->get_billing_city(),
                'state'          => $order->get_billing_state(),
                'zip-code'       => $order->get_billing_postcode(),
                'country'        => $order->get_billing_country(),
            ));
            if ( ! empty( $billing_params ) ) {
                $checkout_url = add_query_arg( $billing_params, $checkout_url );
            }

            // add external order id to the checkout url
            $checkout_url = add_query_arg( array('external_order_id' => $order->get_id()), $checkout_url );

            // by default order status is "pending" (Pending payment) 
            // so no need to do anything additional here
            // $order->get_status();

            return array(
                'result'   => 'success',
                'redirect' => $checkout_url,
            );
        }

        // tokenized card
        $opaque_raw = isset( $_POST['chargx_opaque_data'] ) ? wp_unslash( $_POST['chargx_opaque_data'] ) : '';
        if ( empty( $opaque_raw ) ) {
            wc_add_notice( __( 'There was a problem tokenizing your card. Please try again.', 'chargx-woocommerce' ), 'error' );
            return;
        }
        $opaque_data = json_decode( $opaque_raw, true );
        if ( empty( $opaque_data ) || ! is_array( $opaque_data ) ) {
            wc_add_notice( __( 'Invalid card token received. Please try again.', 'chargx-woocommerce' ), 'error' );
            return;
        }

        // 3ds data
        $three_ds_raw = isset( $_POST['chargx_3ds_data'] ) ? wp_unslash( $_POST['chargx_3ds_data'] ) : '';
        $three_ds_data = null;
        if (!empty( $three_ds_raw ) ) {
          $decoded = json_decode( $three_ds_raw, true );
          if ( !empty( $decoded ) && is_array( $decoded ) ) {
              $three_ds_data = $decoded;
          }
        }
       

        // Build payload.
        $amount   = $order->get_total();
        $currency = $order->get_currency();

        $payload = array(
            'currency'       => $currency,
            'amount'         => (string) $amount,
            'type'           => 'fiat',
            'opaqueData'     => $opaque_data,
            'customer'       => $this->build_customer_from_order( $order ),
            'billingAddress' => $this->build_billing_address_from_order( $order ),
            'orderId'        => (string) $order->get_id(),
        );

        // Conditionally add 3DS block
        if (!empty($three_ds_data)) {
            $payload['threeDS'] = $three_ds_data;
        }

        $api = $this->get_api_client();

        $this->log( 'Processing card payment for order ' . $order->get_id() . ' with payload: ' . ChargX_Logger::encode( $payload ) );

        $response = $api->transact( $payload );

        if ( is_wp_error( $response ) ) {
            $order->update_status('failed', __('Payment has been failed.', 'chargx-woocommerce'));
            $error_message = $response->get_error_message();
            $error_data    = $response->get_error_data();
            $status        = is_array( $error_data ) && isset( $error_data['status'] ) ? $error_data['status'] : '';
            $this->log( 'Payment failed: ' . $status . ' ' . $error_message, 'error' );
            wc_add_notice($error_message, 'error' );
            return;
        }

        $this->log( 'ChargX response: ' . ChargX_Logger::encode( $response ) );

        $result_data = isset( $response['result'] ) ? $response['result'] : array();
        $chargx_order_id = isset( $result_data['orderId'] ) ? $result_data['orderId'] : '';
        $order_display_id = isset( $result_data['orderDisplayId'] ) ? $result_data['orderDisplayId'] : '';

        if ( ! $chargx_order_id ) {
            $this->log( 'Missing ChargX orderId in response.', 'error' );
            wc_add_notice( __( 'Payment failed: missing transaction id.', 'chargx-woocommerce' ), 'error' );
            return;
        }

        $order->update_meta_data( '_chargx_order_id', $chargx_order_id );
        $order->update_meta_data( '_chargx_order_display_id', $order_display_id );
        $order->save();

        if ( 'authorize' === $this->capture_type ) {
            $order->update_status( 'on-hold', __( 'ChargX payment authorized. Capture later via ChargX or gateway.', 'chargx-woocommerce' ) );
        } else {
            $order->payment_complete( $chargx_order_id );
        }

        WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    /**
     * Mark a WooCommerce order paid only after a verified ChargX webhook.
     */
    protected function complete_order( $order_id, $chargx_order_id = null, $chargx_order_display_id = null ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return null;
        }

        $allowed_methods = array( 'chargx_card', 'chargx_bank' );
        if ( ! in_array( $order->get_payment_method(), $allowed_methods, true ) ) {
            return null;
        }

        if ( $order->is_paid() ) {
            return $order;
        }

        if ( empty( $chargx_order_id ) ) {
            return null;
        }

        $order->update_meta_data( '_chargx_order_id', $chargx_order_id );
        if ( ! empty( $chargx_order_display_id ) ) {
            $order->update_meta_data( '_chargx_order_display_id', $chargx_order_display_id );
        }
        $order->payment_complete( $chargx_order_id );
        $order->save();
        return $order;
    }

    /**
     * Browser return from the ChargX Payment Form.
     * Redirects to the thank-you page only — never marks the order as paid.
     */
    public function handle_return() {
        $order_id = absint( isset( $_GET['order_id'] ) ? $_GET['order_id'] : 0 );
        $key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

        $this->log( 'handle_return: order_id: ' . $order_id, 'info' );

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( 'Invalid order', '', array( 'response' => 400 ) );
        }
        if ( $key && ! hash_equals( (string) $order->get_order_key(), $key ) ) {
            wp_die( 'Invalid order', '', array( 'response' => 400 ) );
        }

        $thankyou = add_query_arg( 'chargx_confirm', '1', $this->get_return_url( $order ) );

        set_transient( 'chargx_return_poll_' . $order_id, 1, 5 * MINUTE_IN_SECONDS );
        $order->update_meta_data( '_chargx_pending_confirm', 'yes' );
        $order->save();

        wp_safe_redirect( $thankyou );
        exit;
    }

    /**
     * Shows the confirmation popup on the order received page after payment redirect.
     *
     * @param int $order_id WooCommerce order ID.
     */
    public function show_order_received_popup( $order_id ) {
        if ( self::$popup_rendered ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== $this->id ) {
            return;
        }

        $should_show = 'yes' === $order->get_meta( '_chargx_pending_confirm' )
            || ( isset( $_GET['chargx_confirm'] ) && '1' === $_GET['chargx_confirm'] );

        if ( ! $should_show ) {
            return;
        }

        self::$popup_rendered = true;
        $this->log( 'show_order_received_popup for order ' . $order_id, 'info' );
        $this->render_confirmation_popup( $order_id );
    }

    /**
     * Renders the confirmation popup with optional auto-confirm countdown, then polls order status.
     *
     * @param int $order_id WooCommerce order ID.
     */
    protected function render_confirmation_popup( $order_id ) {
        $this->log( 'render_confirmation_popup. order_id: ' . $order_id, 'info' );

        $status_url = add_query_arg(
            array(
                'wc-api'     => 'chargx_order_status',
                'order_id'   => absint( $order_id ),
                'from_popup' => '1',
            ),
            home_url( '/' )
        );
        ?>
        <div id="chargx-confirm-overlay" class="chargx-overlay" role="dialog" aria-modal="true" aria-labelledby="chargx-confirm-title">
            <div class="chargx-overlay-backdrop" aria-hidden="true"></div>
            <div class="chargx-page">
                <div class="chargx-card chargx-confirm" id="chargx-confirm">
                    <h2 class="chargx-title" id="chargx-confirm-title"><?php esc_html_e( 'Confirm transaction', 'chargx-woocommerce' ); ?></h2>
                    <p class="chargx-subtitle"><?php esc_html_e( 'Confirm the transaction by clicking the button below', 'chargx-woocommerce' ); ?></p>

                    <div class="chargx-countdown-block">
                        <div class="chargx-countdown-bar" aria-hidden="true">
                            <div class="chargx-countdown-bar-fill" id="chargx-countdown-bar"></div>
                        </div>
                        <p class="chargx-countdown-label" id="chargx-countdown" aria-live="polite"></p>
                    </div>

                    <button type="button" class="chargx-confirm-btn" id="chargx-confirm-btn">
                        <?php esc_html_e( 'Confirm', 'chargx-woocommerce' ); ?>
                    </button>
                </div>

                <div class="chargx-card chargx-finalizing" id="chargx-finalizing">
                    <div class="chargx-loader" aria-hidden="true"></div>
                    <p class="chargx-finalizing-text"><?php esc_html_e( 'Finalizing your order and updating inventory...', 'chargx-woocommerce' ); ?></p>
                </div>
            </div>
        </div>
        <style>
            .chargx-overlay {
                --chargx-bg: #f3f3f3;
                --chargx-surface: #ffffff;
                --chargx-border: #e2e2e2;
                --chargx-text: #1a1a1a;
                --chargx-text-muted: #6b6b6b;
                --chargx-btn: #2c2c2c;
                --chargx-btn-hover: #1a1a1a;
                --chargx-track: #e8e8e8;
                --chargx-progress: #8a8a8a;

                position: fixed;
                inset: 0;
                z-index: 999999;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 1rem;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif;
                color: var(--chargx-text);
                line-height: 1.5;
            }

            .chargx-overlay-backdrop {
                position: absolute;
                inset: 0;
                background: rgba(0, 0, 0, 0.45);
            }

            .chargx-overlay .chargx-page {
                position: relative;
                z-index: 1;
                width: min(100%, 480px);
            }

            .chargx-overlay .chargx-card {
                background: var(--chargx-surface);
                border: 1px solid var(--chargx-border);
                border-radius: 4px;
                padding: 2.5rem 2rem;
                text-align: center;
            }

            .chargx-overlay .chargx-title {
                margin: 0 0 0.75rem;
                font-size: 1.375rem;
                font-weight: 600;
                color: var(--chargx-text);
            }

            .chargx-overlay .chargx-subtitle {
                margin: 0 0 2rem;
                color: var(--chargx-text-muted);
                font-size: 0.9375rem;
                line-height: 1.6;
            }

            .chargx-overlay .chargx-countdown-block {
                margin-bottom: 2rem;
            }

            .chargx-overlay .chargx-countdown-bar {
                height: 4px;
                background: var(--chargx-track);
                border-radius: 2px;
                overflow: hidden;
                margin-bottom: 0.75rem;
            }

            .chargx-overlay .chargx-countdown-bar-fill {
                height: 100%;
                width: 100%;
                background: var(--chargx-progress);
                border-radius: 2px;
                transition: width 1s linear;
            }

            .chargx-overlay .chargx-countdown-label {
                margin: 0;
                color: var(--chargx-text-muted);
                font-size: 0.875rem;
            }

            .chargx-overlay .chargx-confirm-btn {
                display: inline-block;
                width: 100%;
                max-width: 280px;
                padding: 0.875rem 1.5rem;
                font-family: inherit;
                font-size: 0.9375rem;
                font-weight: 500;
                color: #ffffff;
                background: var(--chargx-btn);
                border: 1px solid var(--chargx-btn);
                border-radius: 3px;
                cursor: pointer;
                transition: background 0.15s ease, border-color 0.15s ease;
            }

            .chargx-overlay .chargx-confirm-btn:hover {
                background: var(--chargx-btn-hover);
                border-color: var(--chargx-btn-hover);
            }

            .chargx-overlay .chargx-confirm-btn:focus-visible {
                outline: 2px solid #888888;
                outline-offset: 2px;
            }

            .chargx-overlay .chargx-confirm-btn:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }

            .chargx-overlay .chargx-finalizing { display: none; }
            .chargx-overlay .chargx-finalizing.is-active { display: block; }
            .chargx-overlay .chargx-confirm { display: block; }
            .chargx-overlay .chargx-confirm.is-hidden { display: none; }

            .chargx-overlay .chargx-loader {
                width: 36px;
                height: 36px;
                border: 2px solid var(--chargx-track);
                border-top-color: var(--chargx-progress);
                border-radius: 50%;
                animation: chargx-spin 0.8s linear infinite;
                margin: 0 auto 1.25rem;
            }

            .chargx-overlay .chargx-finalizing-text {
                margin: 0;
                color: var(--chargx-text-muted);
                font-size: 0.9375rem;
                line-height: 1.6;
            }

            @keyframes chargx-spin { to { transform: rotate(360deg); } }

            body.chargx-confirm-open {
                overflow: hidden;
            }

            @media (prefers-reduced-motion: reduce) {
                .chargx-overlay .chargx-loader { animation: none; }
                .chargx-overlay .chargx-countdown-bar-fill { transition: none; }
                .chargx-overlay .chargx-confirm-btn { transition: none; }
            }
        </style>
        <script>
            (function() {
                var statusUrl = <?php echo wp_json_encode( $status_url ); ?>;
                var pollInterval = 2000;
                var countdownTotal = 5;
                var countdownSeconds = countdownTotal;
                var confirmed = false;
                var countdownTimer = null;

                var overlay = document.getElementById('chargx-confirm-overlay');
                var confirmSection = document.getElementById('chargx-confirm');
                var finalizingSection = document.getElementById('chargx-finalizing');
                var confirmBtn = document.getElementById('chargx-confirm-btn');
                var countdownEl = document.getElementById('chargx-countdown');
                var countdownBar = document.getElementById('chargx-countdown-bar');

                document.body.classList.add('chargx-confirm-open');

                function updateCountdown(seconds) {
                    countdownEl.textContent = '<?php echo esc_js( __( 'Automatic confirmation in', 'chargx-woocommerce' ) ); ?> ' + seconds;
                    countdownBar.style.width = ((seconds / countdownTotal) * 100) + '%';
                }

                function refreshThankYouPage() {
                    var url = new URL(window.location.href);
                    url.searchParams.delete('chargx_confirm');
                    window.location.replace(url.toString());
                }

                function checkStatus() {
                    fetch(statusUrl)
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.completed) {
                                refreshThankYouPage();
                            }
                        })
                        .catch(function(err) {
                            console.error('checkStatus. error', err);
                        });
                }

                function startPolling() {
                    checkStatus();
                    setInterval(checkStatus, pollInterval);
                }

                function confirmTransaction() {
                    if (confirmed) {
                        return;
                    }
                    confirmed = true;

                    if (countdownTimer) {
                        clearInterval(countdownTimer);
                        countdownTimer = null;
                    }

                    confirmBtn.disabled = true;
                    confirmSection.classList.add('is-hidden');
                    finalizingSection.classList.add('is-active');
                    startPolling();
                }

                updateCountdown(countdownSeconds);
                countdownTimer = setInterval(function() {
                    countdownSeconds -= 1;
                    if (countdownSeconds <= 0) {
                        confirmTransaction();
                        return;
                    }
                    updateCountdown(countdownSeconds);
                }, 1000);

                confirmBtn.addEventListener('click', confirmTransaction);
            })();
        </script>
        <?php
    }

    /**
     * AJAX handler: returns order status for the confirmation popup poll.
     */
    public function ajax_order_status() {
        $order_id = absint( $_GET['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json( array( 'completed' => false, 'status' => '' ) );
        }
        if ( ! get_transient( 'chargx_return_poll_' . $order_id ) ) {
            wp_send_json( array( 'completed' => false, 'status' => '' ) );
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json( array( 'completed' => false, 'status' => '' ) );
        }
        $status = $order->get_status();
        $this->log('ajax_order_status. status: ' . $status, 'info');
        $completed = in_array( $status, array( 'processing', 'completed' ), true );
        $from_popup = isset( $_GET['from_popup'] ) && '1' === $_GET['from_popup'];
        if ( $completed && $from_popup ) {
            delete_transient( 'chargx_return_poll_' . $order_id );
            $order->delete_meta_data( '_chargx_pending_confirm' );
            $order->save();
        }
        wp_send_json( array( 'completed' => $completed, 'status' => $status ) );
    }

    /**
     * Server-to-server payment.succeeded. Marks the order paid only after HMAC verification.
     */
    public function handle_webhook_success_payment() {
        $this->log( 'handle_webhook_success_payment', 'info' );

        $raw_body = file_get_contents( 'php://input' );
        if ( false === $raw_body ) {
            $raw_body = '';
        }

        if ( empty( ChargX_Webhook::secrets() ) ) {
            $this->log( 'handle_webhook_success_payment rejected: signing secret not stored', 'error' );
            status_header( 503 );
            wp_die( 'Webhook secret not configured', '', array( 'response' => 503 ) );
        }

        if ( ! ChargX_Webhook::verify_request( $raw_body ) ) {
            $this->log( 'handle_webhook_success_payment rejected: invalid signature', 'error' );
            status_header( 401 );
            wp_die( 'Invalid signature', '', array( 'response' => 401 ) );
        }

        $payload = json_decode( $raw_body, true );
        if ( ! is_array( $payload ) ) {
            status_header( 400 );
            wp_die( 'Invalid payload', '', array( 'response' => 400 ) );
        }

        $this->log( 'handle_webhook_success_payment payload: ' . ChargX_Logger::encode( $payload ), 'info' );

        $type = isset( $payload['type'] ) ? $payload['type'] : '';
        if ( 'payment.succeeded' !== $type ) {
            wp_send_json( array( 'received' => true, 'ignored' => true ) );
        }

        $object                  = isset( $payload['data']['object'] ) && is_array( $payload['data']['object'] ) ? $payload['data']['object'] : array();
        $order_id                = absint( isset( $object['external_order_id'] ) ? $object['external_order_id'] : 0 );
        $chargx_order_id         = isset( $object['order_id'] ) ? $object['order_id'] : null;
        $chargx_order_display_id = isset( $object['order_display_id'] ) ? $object['order_display_id'] : null;

        $this->log( 'handle_webhook_success_payment. order_id: ' . $order_id, 'info' );

        if ( ! $order_id || empty( $chargx_order_id ) ) {
            wp_send_json( array( 'received' => true, 'ignored' => true ) );
        }

        $order = $this->complete_order( $order_id, $chargx_order_id, $chargx_order_display_id );
        if ( ! $order ) {
            $this->log( 'handle_webhook_success_payment: order not completable ' . $order_id, 'error' );
            wp_send_json( array( 'received' => true, 'ignored' => true ) );
        }

        wp_send_json( array( 'received' => true, 'order_id' => $order->get_id() ) );
    }
}
