<?php
/**
 * CLI checks for ChargX webhook HMAC verification (no WordPress required).
 *
 * Run: php tests/webhook-signature.php
 */
define( 'ABSPATH', true );

function get_option( $key, $default = false ) {
    return $default;
}

function update_option( $key, $value, $autoload = true ) {
    return true;
}

function add_query_arg( $args, $url ) {
    return $url;
}

function home_url( $path = '/' ) {
    return 'https://shop.example' . $path;
}

require dirname( __DIR__ ) . '/includes/class-chargx-webhook.php';

$failed = 0;

function chargx_assert( $ok, $label ) {
    global $failed;
    if ( $ok ) {
        echo "ok  $label\n";
        return;
    }
    $failed++;
    echo "FAIL  $label\n";
}

$secret    = 'whsec_test_secret';
$timestamp = (string) time();
$body      = '{"id":"evt_1","type":"payment.succeeded","data":{"object":{"external_order_id":"12","order_id":"ord_1"}}}';
$digest    = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
$header    = 'v1=' . $digest;

chargx_assert(
    ChargX_Webhook::verify_with_secrets( $body, $header, $timestamp, array( $secret ) ),
    'accepts a valid v1 signature'
);

chargx_assert(
    ! ChargX_Webhook::verify_with_secrets( $body, 'v1=' . str_repeat( '0', 64 ), $timestamp, array( $secret ) ),
    'rejects a wrong digest'
);

chargx_assert(
    ! ChargX_Webhook::verify_with_secrets( $body, $header, $timestamp, array( 'other-secret' ) ),
    'rejects a wrong secret'
);

chargx_assert(
    ! ChargX_Webhook::verify_with_secrets( $body, $header, (string) ( time() - 600 ), array( $secret ) ),
    'rejects a stale timestamp'
);

chargx_assert(
    ! ChargX_Webhook::verify_with_secrets( $body, $header, 'not-a-unix-time', array( $secret ) ),
    'rejects a non-numeric timestamp'
);

chargx_assert(
    ChargX_Webhook::verify_with_secrets( $body, $header, $timestamp, array( 'wrong', $secret ) ),
    'accepts when one of several secrets matches'
);

chargx_assert(
    ChargX_Webhook::extract_v1_signature( 'v1=abc' ) === 'abc',
    'extracts v1= from the signature header'
);

if ( $failed > 0 ) {
    echo "\n$failed failed\n";
    exit( 1 );
}

echo "\nall passed\n";
exit( 0 );
