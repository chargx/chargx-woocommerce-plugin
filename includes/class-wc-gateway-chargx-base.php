<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Base gateway containing common settings & helpers.
 */
abstract class WC_Gateway_ChargX_Base extends WC_Payment_Gateway {

    /**
     * Debug.
     *
     * @var bool
     */
    public $debug;

    /**
     * Publishable key (live).
     *
     * @var string
     */
    public $publishable_key;

    /**
     * Publishable key (test).
     *
     * @var string
     */
    public $test_publishable_key;

    /**
     * Secret key (live, Admin API).
     *
     * @var string
     */
    public $secret_key;

    /**
     * Secret key (test, Admin API).
     *
     * @var string
     */
    public $test_secret_key;

    /**
     * Test mode.
     *
     * @var string yes|no
     */
    public $testmode;


    /**
     * Double-redirect flow
     *
     * @var string yes|no
     */
    public $payment_redirection_flow;
    /**
     * Capture type: capture or authorize.
     *
     * @var string
     */
    public $capture_type;

    /**
     * API client instance (per request).
     *
     * @var ChargX_API_Client|null
     */
    protected $api_client;

    /**
     * ChargX api endpoint.
     *
     * @var ChargX_API_Client|null
     */
    protected $api_endpoint;

    public function __construct() {
        $this->supports = array(
            'products',
            'refunds',
            'subscriptions',
        );

        $this->init_form_fields();
        $this->init_settings();

        $this->title                = $this->get_option( 'title' );
        $this->description          = $this->get_option( 'description' );
        $this->enabled              = $this->get_option( 'enabled', 'no' );
        $this->testmode             = $this->get_option( 'testmode', 'no' );
        $this->payment_redirection_flow = $this->get_option( 'payment_redirection_flow', 'no' );
        $this->publishable_key      = $this->get_option( 'publishable_key' );
        $this->test_publishable_key = $this->get_option( 'test_publishable_key' );
        $this->secret_key           = $this->get_option( 'secret_key' );
        $this->test_secret_key      = $this->get_option( 'test_secret_key' );
        $this->capture_type         = $this->get_option( 'capture_type', 'capture' );
        $this->api_endpoint         = $this->get_option( 'api_endpoint', 'https://api.chargx.io' );
        $this->debug                = 'yes' === $this->get_option( 'debug', 'no' );

        // add 5% discount to the order for bank to bank transfer
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'chargx_bank_transfer_discount' ) );
        add_action( 'wp_footer', array( $this, 'chargx_refresh_checkout_on_payment_change' ) );
    }

    /**
     * Default settings.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'chargx-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable this payment method', 'chargx-woocommerce' ),
                'default' => 'no',
            ),
            'title'   => array(
                'title'       => __( 'Title', 'chargx-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Title seen by customers at checkout.', 'chargx-woocommerce' ),
                'default'     => __( 'Credit Card', 'chargx-woocommerce' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'chargx-woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Description seen by customers at checkout.', 'chargx-woocommerce' ),
                'default'     => __( 'For card payments and your security, click the “Place Order” button below to seamlessly complete your order through a private, encrypted verified card payment processor.', 'chargx-woocommerce' ),
                'desc_tip'    => true,
            ),
            'testmode' => array(
                'title'       => __( 'Sandbox / Test Mode', 'chargx-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable Test Mode (use test keys & sandbox store)', 'chargx-woocommerce' ),
                'default'     => 'yes',
                'description' => __( 'Use your ChargX sandbox store & test card numbers while this is enabled.', 'chargx-woocommerce' ),
            ),
            'publishable_key' => array(
                'title'       => __( 'Live Publishable API Key', 'chargx-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Your ChargX live publishable API key (pk_...).', 'chargx-woocommerce' ),
                'default'     => '',
            ),
            'secret_key' => array(
                'title'       => __( 'Live Secret API Key (Admin API)', 'chargx-woocommerce' ),
                'type'        => 'password',
                'description' => __( 'Your ChargX live Secret API key (sk_...). Used for admin / payouts.', 'chargx-woocommerce' ),
                'default'     => '',
            ),
            'test_publishable_key' => array(
                'title'       => __( 'Test Publishable API Key', 'chargx-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Your ChargX test publishable API key for sandbox.', 'chargx-woocommerce' ),
                'default'     => '',
            ),
            'test_secret_key' => array(
                'title'       => __( 'Test Secret API Key (Admin API)', 'chargx-woocommerce' ),
                'type'        => 'password',
                'description' => __( 'Your ChargX test Secret API key for sandbox Admin API.', 'chargx-woocommerce' ),
                'default'     => '',
            ),
            'capture_type' => array(
                'title'       => __( 'Capture Method', 'chargx-woocommerce' ),
                'type'        => 'select',
                'description' => __( 'Choose whether to immediately capture funds or only authorize.', 'chargx-woocommerce' ),
                'default'     => 'capture',
                'options'     => array(
                    'capture'   => __( 'Authorize and Capture (sale)', 'chargx-woocommerce' ),
                    'authorize' => __( 'Authorize only (capture later)', 'chargx-woocommerce' ),
                ),
            ),
            'payment_redirection_flow' => array(
                'title'       => __( 'Payment Redirection Flow', 'chargx-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable Payment Redirection Flow', 'chargx-woocommerce' ),
                'default'     => 'yes',
                'description' => __( 'Payment Redirection Flow', 'chargx-woocommerce' ),
            ),
            'developers_section' => array(
                'title'       => __( 'Developers Settings', 'chargx-woocommerce' ),
                'type'        => 'title',
                'description' => __( 'Developers related settings', 'chargx-woocommerce' ),
            ),
            'api_endpoint' => array(
                'title'       => __( 'API Endpoint', 'chargx-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Your ChargX API endpoint.', 'chargx-woocommerce' ),
                'default'     => 'https://api.chargx.io',
            ),
            'debug' => array(
                'title'       => __( 'Debug Log', 'chargx-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable logging (logs to WooCommerce > Status > Logs)', 'chargx-woocommerce' ),
                'default'     => 'no',
            ),
        );
    }

    /**
     * Get API client.
     *
     * @return ChargX_API_Client
     */
    public function get_api_client() {
        if ( $this->api_client instanceof ChargX_API_Client ) {

            // reassign if changed
            $this->api_client->set_testmode( 'yes' === $this->testmode );
            $this->api_client->set_publishable_key( $use_test ? $this->test_publishable_key : $this->publishable_key );
            $this->api_client->set_secret_key( $use_test ? $this->test_secret_key : $this->secret_key );
            $this->api_client->set_endpoint( untrailingslashit($this->api_endpoint));
            $this->api_client->set_admin_api_endpoint( trailingslashit(untrailingslashit($this->api_endpoint)) . 'admin');

            return $this->api_client;
        }

        $use_test = ( 'yes' === $this->testmode );

        $pub_key = $use_test ? $this->test_publishable_key : $this->publishable_key;
        $sec_key = $use_test ? $this->test_secret_key : $this->secret_key;

        $this->api_client = new ChargX_API_Client($this->api_endpoint, $pub_key, $sec_key, $use_test);

        return $this->api_client;
    }

    /**
     * Return current publishable key (used in JS).
     *
     * @return string
     */
    public function get_publishable_key() {
        $use_test = ( 'yes' === $this->testmode );
        return $use_test ? $this->test_publishable_key : $this->publishable_key;
    }

    /**
     * Helper: log.
     */
    protected function log( $message, $level = 'info' ) {
        if ( ! $this->debug ) {
            return;
        }
        ChargX_Logger::log( '[' . $this->id . '] ' . $message, $level );
    }

    /**
     * Utility to build customer object from order.
     *
     * @param WC_Order $order
     * @return array
     */
    protected function build_customer_from_order( $order ) {
        return array(
            'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
        );
    }

    /**
     * Build billing address object from order.
     *
     * @param WC_Order $order
     * @return array
     */
    protected function build_billing_address_from_order( $order ) {
        return array(
            'street'      => $order->get_billing_address_1(),
            'unit'        => $order->get_billing_address_2(),
            'city'        => $order->get_billing_city(),
            'state'       => $order->get_billing_state(),
            'zipCode'     => $order->get_billing_postcode(),
            'countryCode' => $order->get_billing_country(),
            'phone'       => $order->get_billing_phone(),
        );
    }

    /**
     * Common refund handler.
     *
     * @param int    $order_id
     * @param float  $amount
     * @param string $reason
     *
     * @return bool|WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'chargx_no_order', __( 'Order not found.', 'chargx-woocommerce' ) );
        }

        $chargx_order_id = $order->get_meta( '_chargx_order_id' );
        if ( ! $chargx_order_id ) {
            return new WP_Error( 'chargx_no_remote_order', __( 'ChargX transaction not found for this order.', 'chargx-woocommerce' ) );
        }

        $this->log( "Refund requested for order {$order->get_id()}, ChargX orderId {$chargx_order_id}, amount {$amount}" );

        $api      = $this->get_api_client();
        $response = $api->refund( $chargx_order_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $order->add_order_note( sprintf( __( 'ChargX refund processed. Reason: %s', 'chargx-woocommerce' ), $reason ) );

        return true;
    }


    function chargx_bank_transfer_discount( $cart ) {
        // $this->log( 'chargx_bank_transfer_discount', 'info' );

        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            $this->log( 'chargx_bank_transfer_discount is_admin && ! defined( DOING_AJAX )', 'info' );
            return;
        }
    
        if ( ! WC()->session ) {
            $this->log( 'chargx_bank_transfer_discount WC()->session not found', 'info' );
            return;
        }
    
        $chosen_payment_method = WC()->session->get( 'chosen_payment_method' );
        // $this->log( 'chargx_bank_transfer_discount chosen_payment_method: ' . $chosen_payment_method, 'info' );

        if ( $chosen_payment_method === 'chargx_bank' ) {
    
            // 5% from subtotal (products only)
            $discount = $cart->get_subtotal() * 0.05;
    
            if ( $discount > 0 ) {
                $cart->add_fee('5% Pay-By-Bank Discount', -$discount );
            }
        }
    }

    function chargx_refresh_checkout_on_payment_change() {
        // $this->log( 'chargx_refresh_checkout_on_payment_change', 'info' );
        if ( ! is_checkout() ) {
            $this->log( 'chargx_refresh_checkout_on_payment_change not is_checkout', 'info' );
            return;
        }
        ?>
        <script type="text/javascript">
            jQuery(function($){
                $('form.checkout').on('change', 'input[name="payment_method"]', function(){
                    $('body').trigger('update_checkout');
                });
            });
        </script>
        <?php
    }
}

