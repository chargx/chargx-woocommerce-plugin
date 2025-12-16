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
    }

    /**
     * Extra settings specific to Card payments.
     */
    public function init_form_fields() {
        parent::init_form_fields();

        $this->form_fields = array_merge(
            $this->form_fields,
            array(
                'apple_section' => array(
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
        </fieldset>
        <?php
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
            wc_add_notice("$error_message. Make sure you entered valid card details. <br><br>Error details: $body", 'error' );
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
}
