<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ChargX webhook signing: store the one-time secret and verify incoming deliveries.
 *
 * ChargX signs each POST with:
 *   Webhook-Timestamp: unix seconds
 *   Webhook-Signature: v1=<hex(hmac_sha256(secret, "{timestamp}.{raw_body}"))>
 */
class ChargX_Webhook {

    const OPTION_KEY           = 'chargx_wc_webhooks';
    const TIMESTAMP_TOLERANCE  = 300;
    const SUCCESS_URL_ENDPOINT = 'wc_gateway_chargx_card_success_url';
    const WEBHOOK_ENDPOINT     = 'wc_gateway_chargx_card_success_url_webhook';

    /**
     * Public storefront URL ChargX redirects the customer to after payment.
     * This URL must never mark an order as paid.
     *
     * @param WC_Order $order
     * @return string
     */
    public static function success_url( $order ) {
        return add_query_arg(
            array(
                'wc-api'   => self::SUCCESS_URL_ENDPOINT,
                'order_id' => $order->get_id(),
                'key'      => $order->get_order_key(),
            ),
            home_url( '/' )
        );
    }

    /**
     * Public webhook URL ChargX POSTs payment.succeeded to.
     *
     * @return string
     */
    public static function webhook_url() {
        return add_query_arg(
            array( 'wc-api' => self::WEBHOOK_ENDPOINT ),
            home_url( '/' )
        );
    }

    /**
     * @return array<string, array{id?: string, secret?: string, url?: string}>
     */
    public static function get_stored() {
        $stored = get_option( self::OPTION_KEY, array() );
        return is_array( $stored ) ? $stored : array();
    }

    /**
     * @param string $mode test|live
     * @return array{id?: string, secret?: string, url?: string}
     */
    public static function get_for_mode( $mode ) {
        $stored = self::get_stored();
        $row    = isset( $stored[ $mode ] ) && is_array( $stored[ $mode ] ) ? $stored[ $mode ] : array();
        return $row;
    }

    /**
     * Persist the webhook secret shown once on create/rotate.
     *
     * @param string $mode   test|live
     * @param string $id     ChargX webhook endpoint id
     * @param string $secret Plain webhook signing secret
     * @param string $url    Registered URL
     */
    public static function save_for_mode( $mode, $id, $secret, $url ) {
        $stored           = self::get_stored();
        $stored[ $mode ]  = array(
            'id'     => (string) $id,
            'secret' => (string) $secret,
            'url'    => (string) $url,
        );
        update_option( self::OPTION_KEY, $stored, false );
    }

    /**
     * True when this site already has a signing secret for the current webhook URL.
     *
     * @param string $mode
     * @param string $url
     * @return bool
     */
    public static function is_configured_for( $mode, $url ) {
        $row = self::get_for_mode( $mode );
        return ! empty( $row['secret'] ) && ! empty( $row['id'] ) && isset( $row['url'] ) && $row['url'] === $url;
    }

    /**
     * @return string[]
     */
    public static function secrets() {
        $secrets = array();
        foreach ( self::get_stored() as $row ) {
            if ( is_array( $row ) && ! empty( $row['secret'] ) ) {
                $secrets[] = (string) $row['secret'];
            }
        }
        return $secrets;
    }

    /**
     * Read a request header (Webhook-Signature, Webhook-Timestamp, …).
     *
     * @param string $name
     * @return string
     */
    public static function get_request_header( $name ) {
        $server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
        if ( ! empty( $_SERVER[ $server_key ] ) ) {
            return (string) $_SERVER[ $server_key ];
        }
        if ( function_exists( 'getallheaders' ) ) {
            $headers = getallheaders();
            if ( is_array( $headers ) ) {
                foreach ( $headers as $key => $value ) {
                    if ( 0 === strcasecmp( (string) $key, $name ) ) {
                        return (string) $value;
                    }
                }
            }
        }
        return '';
    }

    /**
     * Verify ChargX webhook signature using stored secrets.
     *
     * @param string $raw_body
     * @return bool
     */
    public static function verify_request( $raw_body ) {
        return self::verify_with_secrets(
            $raw_body,
            self::get_request_header( 'Webhook-Signature' ),
            self::get_request_header( 'Webhook-Timestamp' ),
            self::secrets()
        );
    }

    /**
     * @param string   $raw_body
     * @param string   $signature_header
     * @param string   $timestamp_header
     * @param string[] $secrets
     * @return bool
     */
    public static function verify_with_secrets( $raw_body, $signature_header, $timestamp_header, $secrets ) {
        if ( '' === $raw_body || '' === $signature_header || '' === $timestamp_header || empty( $secrets ) ) {
            return false;
        }
        if ( ! ctype_digit( (string) $timestamp_header ) ) {
            return false;
        }

        $timestamp = (int) $timestamp_header;
        if ( abs( time() - $timestamp ) > self::TIMESTAMP_TOLERANCE ) {
            return false;
        }

        $provided = self::extract_v1_signature( $signature_header );
        if ( '' === $provided ) {
            return false;
        }

        $signed = $timestamp . '.' . $raw_body;
        foreach ( $secrets as $secret ) {
            $expected = hash_hmac( 'sha256', $signed, (string) $secret );
            if ( hash_equals( $expected, $provided ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $header
     * @return string hex digest without the v1= prefix
     */
    public static function extract_v1_signature( $header ) {
        $parts = preg_split( '/[\s,]+/', (string) $header );
        if ( ! is_array( $parts ) ) {
            return '';
        }
        foreach ( $parts as $part ) {
            if ( 0 === strpos( $part, 'v1=' ) ) {
                return substr( $part, 3 );
            }
        }
        return '';
    }
}
