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
    ChargX_Webhook::matching_modes( $body, $header, $timestamp, array( 'live' => 'wrong', 'test' => $secret ) ) === array( 'test' ),
    'a matching secret returns only its mode'
);

chargx_assert(
    ChargX_Webhook::matching_modes( $body, $header, $timestamp, array( 'live' => 'other', 'test' => 'nope' ) ) === array(),
    'returns no mode when neither secret matches'
);

$live_secret = 'whsec_live_secret';
$live_header = 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $live_secret );
chargx_assert(
    ChargX_Webhook::matching_modes( $body, $live_header, $timestamp, array( 'test' => $secret, 'live' => $live_secret ) ) === array( 'live' ),
    'live signature matches only the live secret'
);

chargx_assert(
    ChargX_Webhook::environment_is_authorized( array( 'test' ), 'test' ),
    'test signature authorizes a test event'
);

chargx_assert(
    ! ChargX_Webhook::environment_is_authorized( array( 'test' ), 'live' ),
    'test signature does not authorize a live event'
);

chargx_assert(
    ! ChargX_Webhook::environment_is_authorized( array( 'test' ), '' ),
    'missing environment is not authorized'
);

chargx_assert(
    ChargX_Webhook::matching_modes( $body, $header, $timestamp, array( 'test' => $secret, 'live' => $secret ) ) === array( 'test', 'live' ),
    'identical secrets match both modes'
);

chargx_assert(
    ChargX_Webhook::environment_is_authorized( array( 'test', 'live' ), 'live' ),
    'a shared secret still has to match the event environment'
);

chargx_assert(
    ChargX_Webhook::amounts_match( '19.99', 19.99 ),
    'amounts match across string and number'
);

chargx_assert(
    ChargX_Webhook::amounts_match( '19.99', array( 'numeric' => 19.99 ) ),
    'amounts match a Medusa numeric amount object'
);

chargx_assert(
    ChargX_Webhook::amounts_match( '19.990', '19.99' ),
    'amounts match when extra precision is the same cents'
);

chargx_assert(
    ! ChargX_Webhook::amounts_match( '100.00', '10.00' ),
    'a smaller payment does not match'
);

chargx_assert(
    ! ChargX_Webhook::amounts_match( '', '10.00' ),
    'missing amount does not match'
);

chargx_assert(
    ChargX_Webhook::currencies_match( 'USD', 'usd' ),
    'currency compare ignores case'
);

chargx_assert(
    ! ChargX_Webhook::currencies_match( 'USD', 'EUR' ),
    'different currencies do not match'
);

chargx_assert(
    ! ChargX_Webhook::currencies_match( '', 'usd' ),
    'missing currency does not match'
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
