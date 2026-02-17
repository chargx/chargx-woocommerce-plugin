<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ChargX Bank gateway.
 *
 * Displays a single "Pay With Bank" button; payment is completed via redirect to ChargX checkout.
 */
class WC_Gateway_ChargX_Bank extends WC_Gateway_ChargX_Base {

    public function __construct() {
        $this->id                 = 'chargx_bank';
        $this->method_title       = __( 'ChargX – Bank', 'chargx-woocommerce' );
        $this->method_description = __( 'Pay with your bank via ChargX.', 'chargx-woocommerce' );
        $this->has_fields         = true;

        parent::__construct();

        add_action( 'woocommerce_api_wc_gateway_chargx_bank', array( $this, 'handle_return' ) );
    }

    /**
     * Extra settings specific to Bank payments.
     */
    public function init_form_fields() {
        parent::init_form_fields();

        if ( isset( $this->form_fields['title'] ) ) {
            $this->form_fields['title']['default'] = __( 'Pay With Bank', 'chargx-woocommerce' );
        }
        if ( isset( $this->form_fields['description'] ) ) {
            $this->form_fields['description']['default'] = __( 'Pay securely with your bank account. You will be redirected to complete the payment.', 'chargx-woocommerce' );
        }
    }

    /**
     * Payment fields: description and a single "Pay With Bank" button.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo '<p>' . wp_kses_post( $this->description ) . '</p>';
        }
        ?>
        <div id="wc-<?php echo esc_attr( $this->id ); ?>-bank-form" class="wc-chargx-bank-form wc-payment-form">
            <button type="submit" class="button alt chargx-bank-button" name="woocommerce_checkout_place_order" id="place_order" value="<?php esc_attr_e( 'Pay With Bank', 'chargx-woocommerce' ); ?>">
                <?php esc_html_e( 'Pay With Bank', 'chargx-woocommerce' ); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Process the payment: create payment request and redirect to ChargX bank checkout.
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

        $payment_redirect_success_url = $this->payment_redirect_success_url;
        if ( empty( $payment_redirect_success_url ) ) {
            $payment_redirect_success_url = WC()->api_request_url( 'wc_gateway_chargx_bank' );
        }
        $separator = ( strpos( $payment_redirect_success_url, '?' ) !== false ) ? '&' : '?';
        $payment_redirect_success_url .= $separator . 'order_id=' . $order->get_id();

        $api      = $this->get_api_client();
        $response = $api->create_payment_request( $order->get_total(), $order->get_currency(), 'bank', $payment_redirect_success_url );

        if ( is_wp_error( $response ) ) {
            wc_add_notice( __( 'Payment could not be started. Please try again.', 'chargx-woocommerce' ), 'error' );
            return;
        }

        $payment_request = isset( $response['payment_request'] ) ? $response['payment_request'] : array();
        $checkout_url    = isset( $payment_request['checkout_url'] ) ? $payment_request['checkout_url'] : '';

        if ( empty( $checkout_url ) ) {
            wc_add_notice( __( 'Payment could not be started. Please try again.', 'chargx-woocommerce' ), 'error' );
            return;
        }

        $checkout_url .= ( strpos( $checkout_url, '?' ) !== false ? '&' : '?' ) . 'success_url=' . rawurlencode( $payment_redirect_success_url );

        return array(
            'result'   => 'success',
            'redirect' => $checkout_url,
        );
    }

    /**
     * Handle return from ChargX after successful bank payment.
     */
    public function handle_return() {
        $order_id = absint( isset( $_GET['order_id'] ) ? $_GET['order_id'] : 0 );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_die( esc_html__( 'Invalid order.', 'chargx-woocommerce' ), 400 );
        }

        if ( ! empty( $_GET['chargx_order_id'] ) ) {
            $order->update_meta_data( '_chargx_order_id', sanitize_text_field( wp_unslash( $_GET['chargx_order_id'] ) ) );
        }
        if ( ! empty( $_GET['chargx_order_display_id'] ) ) {
            $order->update_meta_data( '_chargx_order_display_id', sanitize_text_field( wp_unslash( $_GET['chargx_order_display_id'] ) ) );
        }
        $order->save();

        if ( ! empty( $_GET['chargx_order_id'] ) ) {
            $order->payment_complete( sanitize_text_field( wp_unslash( $_GET['chargx_order_id'] ) ) );
        }

        wp_safe_redirect( $this->get_return_url( $order ) );
        exit;
    }
}
