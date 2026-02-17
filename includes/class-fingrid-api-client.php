<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FinGrid API client for bank-to-bank payments.
 *
 * @see https://developer.fingrid.io/api/overview
 * Flow: create link_token → customer completes bank connection (SDK) → public_token
 *       → exchange for bank_token → initiate payment with bank_token.
 */
class FinGrid_API_Client {

    /**
     * API base URL (e.g. https://api.fingrid.io).
     *
     * @var string
     */
    protected $endpoint;

    /**
     * Client ID.
     *
     * @var string
     */
    protected $client_id;

    /**
     * Client secret.
     *
     * @var string
     */
    protected $client_secret;

    /**
     * @param string $endpoint      Base URL (e.g. https://api.fingrid.io).
     * @param string $client_id     FinGrid client_id.
     * @param string $client_secret FinGrid client secret.
     */
    public function __construct( $endpoint, $client_id, $client_secret ) {
        $this->endpoint       = untrailingslashit( $endpoint );
        $this->client_id      = trim( (string) $client_id );
        $this->client_secret  = trim( (string) $client_secret );
    }

    /**
     * Generic POST with Basic auth (client_id:client_secret).
     *
     * @param string $path API path (e.g. link/token/create).
     * @param array  $body Request body.
     * @return array|WP_Error
     */
    protected function post( $path, $body = array() ) {
        $url = trailingslashit( $this->endpoint ) . ltrim( $path, '/' );

        $body['client_id'] = $this->client_id;
        $body['secret'] = $this->client_secret;

        ChargX_Logger::log( 'FinGrid_API_Client post: ' . $url, 'info' );

        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ),
                'body'    => ! empty( $body ) ? wp_json_encode( $body ) : '',
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
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        ChargX_Logger::log( 'FinGrid_API_Client handle_response: ' . $code . ' ' . $body, 'info' );

        if ( $code < 200 || $code >= 300 ) {
            $message = isset( $data['message'] ) ? $data['message'] : __( 'FinGrid API error.', 'chargx-woocommerce' );
            return new WP_Error( 'fingrid_api_error', $message, array( 'status' => $code, 'body' => $body ) );
        }

        return is_array( $data ) ? $data : array();
    }

    /**
     * Create a link_token for the bank connection flow.
     *
     * @see https://developer.fingrid.io/api/overview – Step 1
     * @param array $options Optional (e.g. redirect_uri, user). Adjust per FinGrid docs.
     * @return array|WP_Error Response containing link_token.
     */
    public function create_link_token( $options = array() ) {
        return $this->post( 'api/custom/link/token/create', $options );
    }

    /**
     * Exchange public_token for bank_token after customer completes bank connection.
     *
     * @see https://developer.fingrid.io/api/overview – Step 4
     * @param string $public_token Token received from FinGrid SDK on frontend.
     * @return array|WP_Error Response containing bank_token.
     */
    public function exchange_public_token( $public_token ) {
        if ( empty( $public_token ) ) {
            return new WP_Error( 'fingrid_missing_token', __( 'Missing public token.', 'chargx-woocommerce' ) );
        }
        return $this->post( 'api/custom/link/public_token/exchange', array( 'public_token' => $public_token ) );
    }

    /**
     * Create Transaction (move_cabbage) – charge customer's bank account.
     *
     * @see https://developer.fingrid.io/api/move-cabbage
     * Endpoint: {env_url}/api/custom/transaction/move_cabbage
     *
     * @param string $bank_token           From exchange_public_token().
     * @param string $connected_acct        Your unique merchant ID (from FinGrid dashboard).
     * @param float  $final_amount         Transaction amount.
     * @param string $transaction_type      e.g. "charge" (pull from customer's bank).
     * @param string $billing_type          "single" or "recurring".
     * @param string $speed                 "same_day" or "next_day".
     * @param float  $application_fee_amount Platform fee (optional, default 0).
     * @return array|WP_Error
     */
    public function move_cabbage( $bank_token, $connected_acct, $final_amount, $transaction_type = 'charge', $billing_type = 'single', $speed = 'same_day', $application_fee_amount = 0 ) {
        if ( empty( $bank_token ) ) {
            return new WP_Error( 'fingrid_missing_bank_token', __( 'Missing bank token.', 'chargx-woocommerce' ) );
        }
        if ( empty( $connected_acct ) ) {
            return new WP_Error( 'fingrid_missing_connected_acct', __( 'Missing connected account (merchant ID).', 'chargx-woocommerce' ) );
        }

        $body = array(
            'bank_token'            => $bank_token,
            'connected_acct'         => (string) $connected_acct,
            'transaction_type'      => (string) $transaction_type,
            'billing_type'          => (string) $billing_type,
            'speed'                 => (string) $speed,
            'final_amount'          => (float) $final_amount,
            'application_fee_amount' => (float) $application_fee_amount,
        );

        return $this->post( 'api/custom/transaction/move_cabbage', $body );
    }
}
