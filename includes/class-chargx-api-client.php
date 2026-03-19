<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Simple ChargX API client.
 */
class ChargX_API_Client {

    /**
     * Live/test.
     *
     * @var bool
     */
    protected $testmode;

    /**
     * Publishable key (public).
     *
     * @var string
     */
    protected $publishable_key;

    /**
     * Secret key (admin API).
     *
     * @var string
     */
    protected $secret_key;

    /**
     * Base URL.
     *
     * @var string
     */
    protected $endpoint;

    /**
     * Admin API endpoint.
     *
     * @var string
     */
    protected $admin_endpoint;

    /**
     * Constructor.
     *
     * @param string      $publishable_key
     * @param string      $secret_key
     * @param bool        $testmode
     * @param string|null $endpoint Optional base API endpoint (e.g. https://api.chargx.io).
     */
    public function __construct($endpoint = 'https://api.chargx.io', $publishable_key, $secret_key = '', $testmode = false) {
        $this->publishable_key = trim( (string) $publishable_key );
        $this->secret_key      = trim( (string) $secret_key );
        $this->testmode        = (bool) $testmode;
        $this->endpoint       = untrailingslashit( $endpoint );
        $this->admin_endpoint = trailingslashit( $this->endpoint ) . 'admin';
    }

    /**
     * Generic GET.
     */
    protected function get( $path, $args = array() ) {
        $url = trailingslashit( $this->endpoint ) . ltrim( $path, '/' );

        $response = wp_remote_get(
            add_query_arg( $args, $url ),
            array(
                'timeout' => 30,
                'headers' => array(
                    'x-publishable-api-key' => $this->publishable_key,
                    'Accept'                => 'application/json',
                ),
            )
        );

        return $this->handle_response( $response );
    }

    /**
     * Generic POST to main API.
     */
    protected function post( $path, $body = array() ) {
        $url = trailingslashit( $this->endpoint ) . ltrim( $path, '/' );


        ChargX_Logger::log( "API post: $url, body: " . wp_json_encode( $body ), 'info' );


        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 30,
                'headers' => array(
                    'x-publishable-api-key' => $this->publishable_key,
                    'Content-Type'          => 'application/json',
                    'Accept'                => 'application/json',
                ),
                'body'    => wp_json_encode( $body ),
            )
        );

        return $this->handle_response( $response );
    }

    /**
     * Generic GET to Admin API with secret key.
     */
    protected function admin_get( $path, $args = array() ) {
        if ( empty( $this->secret_key ) ) {
            return new WP_Error( 'chargx_no_secret', __( 'ChargX secret key is missing.', 'chargx-woocommerce' ) );
        }

        $url = trailingslashit( $this->admin_endpoint ) . ltrim( $path, '/' );
        $url = add_query_arg( $args, $url );

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Basic ' . $this->secret_key,
                    'Accept'        => 'application/json',
                ),
            )
        );

        return $this->handle_response( $response );
    }

    /**
     * Generic POST to Admin API with secret key.
     */
    protected function admin_post( $path, $body = array() ) {
        if ( empty( $this->secret_key ) ) {
            return new WP_Error( 'chargx_no_secret', __( 'ChargX secret key is missing.', 'chargx-woocommerce' ) );
        }

        $url = trailingslashit( $this->admin_endpoint ) . ltrim( $path, '/' );

        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Basic ' . $this->secret_key,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ),
                'body'    => wp_json_encode( $body ),
            )
        );

        return $this->handle_response( $response );
    }

    /**
     * Handle HTTP response.
     *
     * @param array|WP_Error $response
     * @return array|WP_Error
     */
    protected function handle_response( $response ) {
        if ( is_wp_error( $response ) ) {
            ChargX_Logger::log( 'HTTP error: ' . $response->get_error_message(), 'error' );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code < 200 || $code >= 300 ) {
            $message = isset( $data['message'] ) ? $data['message'] : 'Unknown ChargX API error';
            ChargX_Logger::log( "API error ($code): $message | response: $body", 'error' );

            return new WP_Error( 'chargx_api_error', $message, array( 'status' => $code, 'body' => $body ) );
        }

        return is_array( $data ) ? $data : array();
    }

    /**
     * Create payment request (redirect flow).
     *
     * POST /v1/payment-request
     * Mirrors the request in chargx-checkout.js for server-side redirect flow.
     *
     * @param float  $amount      Order total amount.
     * @param string $currency    Order currency code (e.g. usd).
     * @param string $type        Payment type, e.g. "card".
     * @param string $success_url URL to redirect after successful payment.
     * @return array|WP_Error Response with payment_request.checkout_url or error.
     */
    public function create_payment_request( $amount, $currency, $type = 'card', $success_url = '' ) {
        $amount   = (float) $amount;
        $currency = strtolower( (string) $currency );
        $body     = array(
            'amount'      => $amount,
            'currency'    => $currency,
            'type'        => (string) $type,
            'success_url' => (string) $success_url,
        );
        return $this->post( 'v1/payment-request', $body );
    }

    /**
     * Create link token for bank-to-bank connection flow.
     *
     * POST v1/bank-to-bank/create_link_token
     *
     * @param array $params clientName, redirectUri, custPhoneNumber, custEmail, custFirstName, custLastName, themeColor, themeLogo.
     * @return array|WP_Error Response containing link_token.
     */
    public function create_link_token( $params = array() ) {
        $body = array(
            'clientName'      => isset( $params['clientName'] ) ? (string) $params['clientName'] : '',
            'redirectUri'     => isset( $params['redirectUri'] ) ? (string) $params['redirectUri'] : '',
            'custPhoneNumber' => isset( $params['custPhoneNumber'] ) ? (string) $params['custPhoneNumber'] : '',
            'custEmail'       => isset( $params['custEmail'] ) ? (string) $params['custEmail'] : '',
            'custFirstName'   => isset( $params['custFirstName'] ) ? (string) $params['custFirstName'] : '',
            'custLastName'   => isset( $params['custLastName'] ) ? (string) $params['custLastName'] : '',
            'themeColor'      => isset( $params['themeColor'] ) ? (string) $params['themeColor'] : '',
            'themeLogo'       => isset( $params['themeLogo'] ) ? (string) $params['themeLogo'] : '',
        );
        return $this->post( 'v1/bank-to-bank/create_link_token', $body );
    }

    /**
     * Exchange public token for bank token after customer completes bank connection.
     *
     * POST v1/bank-to-bank/public_token_exchange
     *
     * @param string $public_token Token received from Cabbage SDK on frontend.
     * @return array|WP_Error Response containing bank_token.
     */
    public function exchange_public_token( $public_token ) {
        if ( empty( $public_token ) ) {
            return new WP_Error( 'chargx_missing_public_token', __( 'Missing public token.', 'chargx-woocommerce' ) );
        }
        return $this->post( 'v1/bank-to-bank/public_token_exchange', array( 'publicToken' => (string) $public_token ) );
    }

    /**
     * Bank-to-bank transaction (after bank connection flow).
     *
     * POST v1/transact/bank-to-bank
     *
     * @param string $bank_token From exchange_public_token().
     * @param float  $amount     Order total.
     * @param string $order_id   WooCommerce order ID.
     * @param string $link_token 
     * @return array|WP_Error
     */
    public function transact_bank_to_bank( $bank_token, $link_token, $amount, $order_id) {
        if ( empty( $bank_token ) ) {
            return new WP_Error( 'chargx_missing_bank_token', __( 'Missing bank token.', 'chargx-woocommerce' ) );
        }
        $body = array(
            'bankToken' => (string) $bank_token,
            'amount'    => (float) $amount,
            'orderId'   => (string) $order_id,
            'linkToken' => (string) $link_token,
        );
        return $this->post( 'v1/bank-to-bank/transact', $body );
    }

    /**
     * Retrieve pretransact keys.
     *
     * GET /pretransact
     */
    public function pretransact() {
        return $this->get( 'pretransact' );
    }

    /**
     * Charge credit card (authorize + capture).
     *
     * POST /transact
     */
    public function transact( $payload ) {
        return $this->post( 'transact', $payload );
    }

    /**
     * Authorize credit card.
     *
     * POST /card/authorize
     */
    public function authorize( $payload ) {
        return $this->post( 'card/authorize', $payload );
    }

    /**
     * Capture previously authorized transaction.
     *
     * POST /transaction/capture
     */
    public function capture( $order_id ) {
        return $this->post( 'transaction/capture', array( 'orderId' => (string) $order_id ) );
    }

    /**
     * Refund transaction.
     *
     * POST /transaction/refund
     */
    public function refund( $order_id ) {
        return $this->post( 'transaction/refund', array( 'orderId' => (string) $order_id ) );
    }

    /**
     * Payout via Admin API.
     *
     * POST /admin/payout
     */
    public function payout( $payload ) {
        return $this->admin_post( 'payout', $payload );
    }

    /**
     * Retrieve webhooks (Admin API).
     *
     * GET /admin/webhook
     *
     * @return array|WP_Error Response with webhook_endpoints key, or error.
     */
    public function get_webhooks() {
        return $this->admin_get( 'webhook' );
    }

    /**
     * Create webhook (Admin API).
     *
     * POST /admin/webhook
     *
     * @param string   $url         Webhook URL.
     * @param string   $name        Webhook name (e.g. "WOO").
     * @param string[] $events      Event names (e.g. ["payment.succeeded"]).
     * @param bool     $enabled     Whether the webhook is enabled.
     * @param string   $environment "test" or "live".
     * @return array|WP_Error
     */
    public function create_webhook( $url, $name = 'WOO', $events = array( 'payment.succeeded' ), $enabled = true) {
        $environment = $this->testmode ? 'test' : 'live';
        
        $body = array(
            'url'         => $url,
            'name'        => $name,
            'events'      => $events,
            'enabled'     => (bool) $enabled,
            'environment' => (string) $environment,
        );
        return $this->admin_post( 'webhook', $body );
    }

    /**
     * Get publishable key.
     */
    public function get_publishable_key() {
        return $this->publishable_key;
    }
}
