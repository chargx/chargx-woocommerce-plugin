<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ChargX Google Pay gateway.
 *
 * This displays as a separate payment method in WooCommerce.
 * It uses PaymentRequest on the frontend and ChargX /transact on the backend.
 */
class WC_Gateway_ChargX_GooglePay extends WC_Gateway_ChargX_Base {
    public function __construct() {
        $this->id                 = 'chargx_googlepay';
        $this->method_title       = __( 'ChargX – Google Pay', 'chargx-woocommerce' );
        $this->method_description = __( 'Google Pay payments via ChargX.', 'chargx-woocommerce' );
        $this->has_fields         = true;

        parent::__construct();
    }

    /**
     * Extra settings specific to Google Pay.
     */
    public function init_form_fields() {
        parent::init_form_fields();

        // Override the title default value from parent
        if ( isset( $this->form_fields['title'] ) ) {
            $this->form_fields['title']['default'] = __( 'Google Pay', 'chargx-woocommerce' );
        }
    }

    /**
     * GooglePay payment fields: only a button & notice, real UI is handled via JS + PaymentRequest.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo '<p>' . wp_kses_post( $this->description ) . '</p>';
        }
        ?>
        <div id="chargx-googlepay-container" class="chargx-googlepay-container">
            <p class="chargx-googlepay-note">
                <?php esc_html_e( 'Click the button below to pay with Google Pay.', 'chargx-woocommerce' ); ?>
            </p>
            <button type="button" id="chargx-googlepay-button" class="chargx-googlepay-button">
                <span class="chargx-googlepay-logo"></span>
                <span><?php esc_html_e( 'Google&nbsp;Pay', 'chargx-woocommerce' ); ?></span>
            </button>
            <input type="hidden" id="chargx-googlepay-token" name="chargx_googlepay_token" value="" />
        </div>
        <?php
    }

    /**
     * Process Google Pay payment using opaque token from front-end ApplePaySession.
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wc_add_notice( __( 'Order not found.', 'chargx-woocommerce' ), 'error' );
            return;
        }

        $token_base64 = isset( $_POST['chargx_googlepay_token'] ) ? wp_unslash( $_POST['chargx_googlepay_token'] ) : '';

        if ( empty( $token_base64 ) ) {
            wc_add_notice( __( 'Missing Google Pay payment token. Please try again.', 'chargx-woocommerce' ), 'error' );
            return;
        }

        $amount   = $order->get_total();
        $currency = $order->get_currency();

        $opaqueData = array(
            'dataDescriptor' => 'COMMON.GOOGLE.INAPP.PAYMENT',
            'dataValue'      => $token_base64,
        );

        $payload = array(
            'currency'       => $currency,
            'amount'         => (string) $amount,
            'type'           => 'fiat',
            'opaqueData'     => $opaqueData,
            'customer'       => $this->build_customer_from_order( $order ),
            'billingAddress' => $this->build_billing_address_from_order( $order ),
            'orderId'        => (string) $order->get_id(),
        );

        $api = $this->get_api_client();

        $this->log( 'Processing Google Pay payment for order ' . $order->get_id() );

        // Google Pay is always "sale" (authorize+capture).
        $response = $api->transact( $payload );

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

        $this->log( 'Google Pay ChargX response: ' . wp_json_encode( $response ) );

        $result_data      = isset( $response['result'] ) ? $response['result'] : array();
        $chargx_order_id  = isset( $result_data['orderId'] ) ? $result_data['orderId'] : '';
        $order_display_id = isset( $result_data['orderDisplayId'] ) ? $result_data['orderDisplayId'] : '';

        if ( ! $chargx_order_id ) {
            $this->log( 'Missing ChargX orderId in Google Pay response.', 'error' );
            wc_add_notice( __( 'Payment failed: missing transaction id.', 'chargx-woocommerce' ), 'error' );
            return;
        }

        // add metadata to order
        $order->update_meta_data('_chargx_order_id', $chargx_order_id );
        $order->update_meta_data('_chargx_order_display_id', $order_display_id );
        // For subscriptions, we can’t reuse opaqueData easily, but store anyway.
        $order->update_meta_data('_chargx_opaque_data', wp_json_encode($opaqueData));
        $order->save();

        $order->payment_complete( $chargx_order_id );
        WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }
}
