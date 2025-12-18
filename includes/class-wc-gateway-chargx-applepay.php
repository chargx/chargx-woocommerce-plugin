<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ChargX Apple Pay gateway.
 *
 * This displays as a separate payment method in WooCommerce.
 * It uses ApplePaySession on the frontend and ChargX /transact on the backend.
 */
class WC_Gateway_ChargX_ApplePay extends WC_Gateway_ChargX_Base {

    /**
     * Apple merchant id.
     *
     * @var string
     */
    public $apple_merchant_id;

    /**
     * Apple merchant display name.
     *
     * @var string
     */
    public $apple_merchant_name;

    /**
     * Apple merchant certificate path (PEM) on server.
     *
     * @var string
     */
    public $apple_cert_path;

    /**
     * Apple merchant certificate key path (PEM/private key).
     *
     * @var string
     */
    public $apple_key_path;

    /**
     * Apple merchant key passphrase.
     *
     * @var string
     */
    public $apple_key_passphrase;

    /**
     * Apple Pay domain (your store domain).
     *
     * @var string
     */
    public $apple_merchant_domain;

    public function __construct() {
        $this->id                 = 'chargx_applepay';
        $this->method_title       = __( 'ChargX – Apple Pay', 'chargx-woocommerce' );
        $this->method_description = __( 'Apple Pay payments via ChargX.', 'chargx-woocommerce' );
        $this->has_fields         = true;

        parent::__construct();

        $this->apple_merchant_id     = $this->get_option( 'apple_merchant_id' );
        $this->apple_merchant_name   = $this->get_option( 'apple_merchant_name', get_bloginfo( 'name' ) );
        $this->apple_cert_path       = $this->get_option( 'apple_cert_path' );
        $this->apple_key_path        = $this->get_option( 'apple_key_path' );
        $this->apple_key_passphrase  = $this->get_option( 'apple_key_passphrase' );
        $this->apple_merchant_domain = $this->get_option( 'apple_merchant_domain', parse_url( home_url(), PHP_URL_HOST ) );
    }

    /**
     * Extra settings specific to Apple Pay.
     */
    public function init_form_fields() {
        parent::init_form_fields();

        // Override the title default value from parent
        if ( isset( $this->form_fields['title'] ) ) {
            $this->form_fields['title']['default'] = __( 'Apple Pay', 'chargx-woocommerce' );
        }

        $this->form_fields = array_merge(
            $this->form_fields,
            array(
                'apple_section' => array(
                    'title'       => __( 'Apple Pay Settings', 'chargx-woocommerce' ),
                    'type'        => 'title',
                    'description' => __( 'Configure your Apple Pay merchant ID, certificates and domain. You must complete Apple Pay on the Web setup in your Apple Developer account.', 'chargx-woocommerce' ),
                ),
                'apple_merchant_id' => array(
                    'title'       => __( 'Apple Pay Merchant ID', 'chargx-woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'Apple Pay Merchant ID configured in your Apple Developer account.', 'chargx-woocommerce' ),
                    'default'     => '',
                ),
                'apple_merchant_name' => array(
                    'title'       => __( 'Merchant Display Name', 'chargx-woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'Name shown in Apple Pay sheet. Defaults to your site name.', 'chargx-woocommerce' ),
                    'default'     => get_bloginfo( 'name' ),
                ),
                'apple_merchant_domain' => array(
                    'title'       => __( 'Merchant Domain', 'chargx-woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'Fully qualified domain registered for Apple Pay (e.g. example.com).', 'chargx-woocommerce' ),
                    'default'     => parse_url( home_url(), PHP_URL_HOST ),
                ),
                'apple_cert_path' => array(
                    'title'       => __( 'Merchant Identity Certificate Path (PEM)', 'chargx-woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'Absolute server path to your Apple Pay merchant identity certificate (.pem).', 'chargx-woocommerce' ),
                    'default'     => '',
                ),
                'apple_key_path' => array(
                    'title'       => __( 'Merchant Private Key Path (PEM)', 'chargx-woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'Absolute server path to your merchant private key (.pem).', 'chargx-woocommerce' ),
                    'default'     => '',
                ),
                'apple_key_passphrase' => array(
                    'title'       => __( 'Merchant Key Passphrase (optional)', 'chargx-woocommerce' ),
                    'type'        => 'password',
                    'description' => __( 'Passphrase for the private key, if set.', 'chargx-woocommerce' ),
                    'default'     => '',
                ),
            )
        );
    }

    /**
     * Apple Pay payment fields: only a button & notice, real UI is handled via JS + ApplePaySession.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo '<p>' . wp_kses_post( $this->description ) . '</p>';
        }
        ?>
        <div id="chargx-applepay-container" class="chargx-applepay-container">
            <p class="chargx-applepay-note">
                <?php esc_html_e( 'Click the button below to pay with Apple Pay.', 'chargx-woocommerce' ); ?>
            </p>
            <button type="button" id="chargx-applepay-button" class="chargx-applepay-button">
                <span class="chargx-applepay-logo"></span>
                <span><?php esc_html_e( 'Apple&nbsp;Pay', 'chargx-woocommerce' ); ?></span>
            </button>
            <input type="hidden" id="chargx-applepay-token" name="chargx_applepay_token" value="" />
        </div>
        <?php
    }

    /**
     * Process Apple Pay payment using opaque token from front-end ApplePaySession.
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wc_add_notice( __( 'Order not found.', 'chargx-woocommerce' ), 'error' );
            return;
        }

        $token_base64 = isset( $_POST['chargx_applepay_token'] ) ? wp_unslash( $_POST['chargx_applepay_token'] ) : '';

        if ( empty( $token_base64 ) ) {
            wc_add_notice( __( 'Missing Apple Pay payment token. Please try again.', 'chargx-woocommerce' ), 'error' );
            return;
        }

        $amount   = $order->get_total();
        $currency = $order->get_currency();

        $payload = array(
            'currency'       => $currency,
            'amount'         => (string) $amount,
            'type'           => 'fiat',
            'opaqueData'     => array(
                'dataDescriptor' => 'COMMON.APPLE.INAPP.PAYMENT',
                'dataValue'      => $token_base64,
            ),
            'customer'       => $this->build_customer_from_order( $order ),
            'billingAddress' => $this->build_billing_address_from_order( $order ),
            'orderId'        => (string) $order->get_id(),
        );

        $api = $this->get_api_client();

        $this->log( 'Processing Apple Pay payment for order ' . $order->get_id() );

        // Apple Pay is always "sale" (authorize+capture).
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

        $this->log( 'Apple Pay ChargX response: ' . wp_json_encode( $response ) );

        $result_data      = isset( $response['result'] ) ? $response['result'] : array();
        $chargx_order_id  = isset( $result_data['orderId'] ) ? $result_data['orderId'] : '';
        $order_display_id = isset( $result_data['orderDisplayId'] ) ? $result_data['orderDisplayId'] : '';

        if ( ! $chargx_order_id ) {
            $this->log( 'Missing ChargX orderId in Apple Pay response.', 'error' );
            wc_add_notice( __( 'Payment failed: missing transaction id.', 'chargx-woocommerce' ), 'error' );
            return;
        }

        $order->update_meta_data( '_chargx_order_id', $chargx_order_id );
        $order->update_meta_data( '_chargx_order_display_id', $order_display_id );
        // For subscriptions, we can’t reuse opaqueData easily, but store anyway.
        $order->update_meta_data( '_chargx_opaque_data', wp_json_encode( array(
            'dataDescriptor' => 'COMMON.APPLE.INAPP.PAYMENT',
            'dataValue'      => $token_base64,
        ) ) );
        $order->save();

        $order->payment_complete( $chargx_order_id );
        WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    /**
     * Validate merchant with Apple Pay servers.
     *
     * This is used in the Ajax handler in the main plugin file.
     *
     * @param string $validation_url
     * @return array|WP_Error
     */
    public function validate_apple_pay_merchant( $validation_url ) {
        if ( empty( $this->apple_merchant_id ) || empty( $this->apple_cert_path ) || empty( $this->apple_key_path ) ) {
            return new WP_Error(
                'chargx_applepay_not_configured',
                __( 'Apple Pay merchant credentials are not fully configured in the gateway settings.', 'chargx-woocommerce' )
            );
        }

        $payload = array(
            'merchantIdentifier' => $this->apple_merchant_id,
            'domainName'         => $this->apple_merchant_domain,
            'displayName'        => $this->apple_merchant_name,
        );

        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $validation_url );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $payload ) );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Content-Type: application/json' ) );
        curl_setopt( $ch, CURLOPT_SSLCERT, $this->apple_cert_path );
        curl_setopt( $ch, CURLOPT_SSLKEY, $this->apple_key_path );

        if ( ! empty( $this->apple_key_passphrase ) ) {
            curl_setopt( $ch, CURLOPT_SSLCERTPASSWD, $this->apple_key_passphrase );
            curl_setopt( $ch, CURLOPT_SSLKEYPASSWD, $this->apple_key_passphrase );
        }

        $response_body = curl_exec( $ch );
        $http_code     = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

        if ( curl_errno( $ch ) ) {
            $error = curl_error( $ch );
            curl_close( $ch );

            $this->log( 'Apple Pay merchant validation cURL error: ' . $error, 'error' );

            return new WP_Error( 'chargx_applepay_curl', $error );
        }

        curl_close( $ch );

        $data = json_decode( $response_body, true );

        if ( $http_code < 200 || $http_code >= 300 || ! is_array( $data ) ) {
            $this->log( 'Apple Pay merchant validation failed: ' . $response_body, 'error' );
            return new WP_Error(
                'chargx_applepay_validation_failed',
                __( 'Apple Pay merchant validation failed. Check your Apple Pay configuration.', 'chargx-woocommerce' )
            );
        }

        return $data;
    }
}
