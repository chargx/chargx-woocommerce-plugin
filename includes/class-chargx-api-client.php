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
    protected $endpoint = 'https://api.chargx.io';

    /**
     * Admin API endpoint.
     *
     * @var string
     */
    protected $admin_endpoint = 'https://api.chargx.io/admin';

    /**
     * Constructor.
     *
     * @param string $publishable_key
     * @param string $secret_key
     * @param bool   $testmode
     */
    public function __construct( $publishable_key, $secret_key = '', $testmode = false ) {
        $this->publishable_key = trim( (string) $publishable_key );
        $this->secret_key      = trim( (string) $secret_key );
        $this->testmode        = (bool) $testmode;
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
     * Void transaction.
     *
     * POST /transaction/void
     */
    public function void( $order_id ) {
        return $this->post( 'transaction/void', array( 'orderId' => (string) $order_id ) );
    }

    /**
     * Create subscription for recurring payments.
     *
     * POST /subscription
     */
    public function create_subscription( $payload ) {
        return $this->post( 'subscription', $payload );
    }

    /**
     * Retrieve subscription.
     *
     * GET /subscription/<id>
     */
    public function get_subscription( $id ) {
        $path = 'subscription/' . rawurlencode( $id );

        return $this->get( $path );
    }

    /**
     * Delete subscription.
     *
     * DELETE /subscription/<id>
     */
    public function delete_subscription( $id ) {
        $url = trailingslashit( $this->endpoint ) . 'subscription/' . rawurlencode( $id );

        $response = wp_remote_request(
            $url,
            array(
                'method'  => 'DELETE',
                'timeout' => 30,
                'headers' => array(
                    'x-publishable-api-key' => $this->publishable_key,
                    'Content-Type'          => 'application/json',
                    'Accept'                => 'application/json',
                ),
            )
        );

        return $this->handle_response( $response );
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
     * Get publishable key.
     */
    public function get_publishable_key() {
        return $this->publishable_key;
    }
}
