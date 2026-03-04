<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ChargX Card gateway.
 */
class WC_Gateway_ChargX_Card extends WC_Gateway_ChargX_Base {

    public function __construct() {
        $this->id                 = 'chargx_card';
        $this->method_title       = __( 'ChargX – Credit Card', 'chargx-woocommerce' );
        $this->method_description = __( 'Pay securely with credit/debit cards via ChargX.', 'chargx-woocommerce' );
        $this->has_fields         = true;
        parent::__construct();

        add_action('woocommerce_api_wc_gateway_chargx_card_success_url', [$this, 'handle_return']);
        add_action('woocommerce_api_wc_gateway_chargx_card_success_url_webhook', [$this, 'handle_webhook_success_payment']);
        add_action('woocommerce_api_chargx_order_status', [$this, 'ajax_order_status']);

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
        
        $this->register_webhook( );
    }

    /**
     * Register webhook with ChargX if it does not already exist (for the given environment)
     */
    private function register_webhook( ) {
        $this->log( 'register_webhook ', 'info' );

        $api = $this->get_api_client();
        $webhook_url = home_url( '/?wc-api=wc_gateway_chargx_card_success_url_webhook' );

        $existing = $api->get_webhooks();
        if ( is_wp_error( $existing ) ) {
            $this->log( 'register_webhook get_webhooks error: ' . $existing->get_error_message(), 'error' );
            return;
        }

        $endpoints = isset( $existing['webhook_endpoints'] ) && is_array( $existing['webhook_endpoints'] )
            ? $existing['webhook_endpoints']
            : array();

        $environment = 'yes' === $this->testmode  ? 'test' : 'live';
        $this->log( 'register_webhook testmode' . $this->testmode, 'info' );
        $this->log( 'register_webhook environment' . $environment, 'info' );


        foreach ( $endpoints as $endpoint ) {
            $ep_url = isset( $endpoint['url'] ) ? $endpoint['url'] : '';
            $ep_env = isset( $endpoint['environment'] ) ? $endpoint['environment'] : '';
            if ( $ep_url === $webhook_url && $ep_env === $environment ) {
                $this->log( 'register_webhook already exists: ' . $webhook_url . ' (env: ' . $environment . ')', 'info' );
                return;
            }
        }

        $result = $api->create_webhook(
            $webhook_url,
            'WOO',
            array( 'payment.succeeded' ),
            true
        );
        if ( is_wp_error( $result ) ) {
            $this->log( 'register_webhook create error: ' . $result->get_error_message(), 'error' );
            return;
        }
        $this->log( 'register_webhook created: ' . $webhook_url, 'info' );
    }

    /**
     * Extra settings specific to Card payments.
     */
    public function init_form_fields() {
        parent::init_form_fields();

        $this->form_fields = array_merge(
            $this->form_fields,
            array(
                '3ds_section' => array(
                    'title'       => __( '3DS Settings', 'chargx-woocommerce' ),
                    'type'        => 'title',
                    'description' => __( 'Configure 3-D Secure to reduce your e-commerce payment fraud risk and increases customer confidence.', 'chargx-woocommerce' ),
                ),
                'enable_3ds' => array(
                    'title'       => __( 'Enable 3-D Secure', 'chargx-woocommerce' ),
                    'type'        => 'checkbox',
                    'description' => __( 'Enable 3-D Secure', 'chargx-woocommerce' ),
                    'default'     => 'no',
                ),
                '3ds_mount_element_selector' => array(
                    'title'       => __( 'DOM element selector', 'chargx-woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'Mount the 3-D Secure UI to the DOM by providing a selector.', 'chargx-woocommerce' ),
                    'default'     => '#threeds-placeholder',
                ),
            )
        );
    }

    /**
     * Payment fields on checkout page.
     * NOTE: card inputs deliberately do NOT have name attributes, so card data is never posted to your server.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo '<p>' . wp_kses_post( $this->description ) . '</p>';
        }

        if ( 'yes' !== $this->get_option( 'payment_redirection_flow', 'no' ) ) {
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

            if ( 'yes' === $this->get_option( 'enable_3ds' ) ) {
                echo '<div id="threeds-placeholder" style="text-align: center;"></div>';
            }
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
        if ( 'yes' === $this->get_option( 'payment_redirection_flow', 'no' ) ) {
            // 
            $api = $this->get_api_client();
            $this->log( 'home_url: ' . home_url() );
            $payment_redirect_success_url = home_url("/?wc-api=wc_gateway_chargx_card_success_url");
            $this->log( 'payment_redirect_success_url: ' . $payment_redirect_success_url, 'info' );
            
            $separator = ( strpos( $payment_redirect_success_url, '?' ) !== false ) ? '&' : '?';
            $payment_redirect_success_url .= $separator . 'order_id=' . $order->get_id();
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

        $this->log( 'Processing card payment for order ' . $order->get_id() . ' with payload: ' . wp_json_encode( array_diff_key( $payload, array( 'opaqueData' => true ) ) ) );

        if ( 'authorize' === $this->capture_type ) {
            $response = $api->authorize( $payload );
        } else {
            $response = $api->transact( $payload );
        }

        if ( is_wp_error( $response ) ) {
            $order->update_status('failed', __('Payment has been failed.', 'chargx-woocommerce'));
            $error_message = $response->get_error_message();
            $body = $response->get_error_data()['body'];
            $status = $response->get_error_data()['status'];
            $this->log("Payment failed : $status: $body", 'error' );
            // wc_add_notice("$error_message. Make sure you entered valid card details. <br><br>Error details: $body", 'error' );
            wc_add_notice($error_message, 'error' );
            return;
        }

        $this->log( 'ChargX response: ' . wp_json_encode( $response ) );

        $result_data = isset( $response['result'] ) ? $response['result'] : array();
        $chargx_order_id = isset( $result_data['orderId'] ) ? $result_data['orderId'] : '';
        $order_display_id = isset( $result_data['orderDisplayId'] ) ? $result_data['orderDisplayId'] : '';

        if ( ! $chargx_order_id ) {
            $this->log( 'Missing ChargX orderId in response.', 'error' );
            wc_add_notice( __( 'Payment failed: missing transaction id.', 'chargx-woocommerce' ), 'error' );
            return;
        }

        // add metadata to order
        $order->update_meta_data( '_chargx_order_id', $chargx_order_id );
        $order->update_meta_data( '_chargx_order_display_id', $order_display_id );
        // For subscriptions, we can’t reuse opaqueData easily, but store anyway.
        $order->update_meta_data( '_chargx_opaque_data', wp_json_encode( $opaque_data ) );
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
     * Applies ChargX return data to the order (payment complete + display id).
     */
    protected function complete_order( $order_id, $chargx_order_id = null, $chargx_order_display_id = null ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return null;
        }

        if ( ! empty( $chargx_order_id ) ) {
            $order->payment_complete( $chargx_order_id );
        }
        if ( ! empty( $chargx_order_display_id ) ) {
            $order->update_meta_data( '_chargx_order_display_id', $chargx_order_display_id );
        }

        $order->save();
        return $order;
    }

    // return from Payment Form redirection flow
    public function handle_return() {
        // http://localhost:8080/?wc-api=wc_gateway_chargx_card_success_url&order_id=123
        $order_id              = absint( $_GET['order_id'] ?? 0 );
        $chargx_order_id       = isset( $_GET['chargx_order_id'] ) ? sanitize_text_field( wp_unslash( $_GET['chargx_order_id'] ) ) : null;
        $chargx_order_display_id = isset( $_GET['chargx_order_display_id'] ) ? sanitize_text_field( wp_unslash( $_GET['chargx_order_display_id'] ) ) : null;

        $this->log( 'handle_return: order_id: ' . $order_id, 'info' );
        $this->log( 'handle_return: chargx_order_id: ' . $chargx_order_id, 'info' );
        $this->log( 'handle_return: chargx_order_display_id: ' . $chargx_order_display_id, 'info' );

        $order = $this->complete_order( $order_id, $chargx_order_id, $chargx_order_display_id );
        if ( ! $order ) {
            wp_die( 'Invalid order', 400 );
        }

        $thankyou = $this->get_return_url( $order );

        // Allow polling for this order for 5 minutes (only from this return flow).
        set_transient( 'chargx_return_poll_' . $order_id, 1, 5 * MINUTE_IN_SECONDS );

        $this->render_finalizing_page( $order_id, $thankyou );
        exit;
    }

    /**
     * Renders the "Finalizing your order" intermediate page with loader and status polling.
     *
     * @param int    $order_id   WooCommerce order ID.
     * @param string $thankyou_url URL to redirect to when order is completed.
     */
    protected function render_finalizing_page( $order_id, $thankyou_url ) {
        $this->log('render_finalizing_page. order_id: ' . $order_id, 'info');

        $status_url = home_url( '/?wc-api=chargx_order_status&order_id=' . absint( $order_id ) );
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php esc_html_e( 'Finalizing your order', 'chargx-woocommerce' ); ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, sans-serif; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f5f5f5; }
                .chargx-finalizing { text-align: center; padding: 2rem; }
                .chargx-finalizing p { color: #333; font-size: 1.125rem; margin-bottom: 1.5rem; }
                .chargx-loader { width: 40px; height: 40px; border: 3px solid #e0e0e0; border-top-color: #333; border-radius: 50%; animation: chargx-spin 0.8s linear infinite; margin: 0 auto 1.5rem; }
                @keyframes chargx-spin { to { transform: rotate(360deg); } }
            </style>
        </head>
        <body>
            <div class="chargx-finalizing">
                <div class="chargx-loader" aria-hidden="true"></div>
                <p><?php esc_html_e( 'Finalizing your order and updating inventory...', 'chargx-woocommerce' ); ?></p>
            </div>
            <script>
                (function() {
                    var statusUrl = <?php echo wp_json_encode( $status_url ); ?>;
                    var thankYouUrl = <?php echo wp_json_encode( $thankyou_url ); ?>;
                    var interval = 2000;

                    function checkStatus() {
                        fetch(statusUrl)
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                console.log('checkStatus. data: ' + JSON.stringify(data));
                                if (data.completed) {
                                    window.location.href = thankYouUrl;
                                }
                            })
                            .catch(function(err) {
                                console.error('checkStatus. error', err);
                            });
                    }

                    checkStatus();
                    setInterval(checkStatus, interval);
                })();
            </script>
        </body>
        </html>
        <?php
    }

    /**
     * AJAX handler: returns order status for the finalizing-page poll. Order is "completed" when status is processing or completed.
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
        wp_send_json( array( 'completed' => $completed, 'status' => $status ) );
    }

    // handle webhook success payment
    public function handle_webhook_success_payment() {
        $this->log( 'handle_webhook_success_payment', 'info' );
        // $this->log('handle_webhook_success_payment headers: ' . wp_json_encode(getallheaders()), 'info');

        $raw_body = file_get_contents('php://input');
        // $this->log('raw_body: ' . $raw_body, 'info');
    
        $payload = json_decode($raw_body, true);
        // $this->log('payload: ' . wp_json_encode($payload), 'info');
    
        $order_id = absint($payload['data']['object']['external_order_id'] ?? 0);
        $chargx_order_id = $payload['data']['object']['order_id'] ?? null;
        $chargx_order_display_id = $payload['data']['object']['order_display_id'] ?? null;

        $this->log('handle_webhook_success_payment. order_id: ' . $order_id, 'info');
        $this->log('handle_webhook_success_payment. chargx_order_id: ' . $chargx_order_id, 'info');
        $this->log('handle_webhook_success_payment. chargx_order_display_id: ' . $chargx_order_display_id, 'info');

        $order = $this->complete_order( $order_id, $chargx_order_id, $chargx_order_display_id );
    }
}
