<?php
/**
 * CLI checks for ChargX log redaction (no WordPress required).
 *
 * Run: php tests/logger-redact.php
 */
define( 'ABSPATH', true );

require dirname( __DIR__ ) . '/includes/class-chargx-logger.php';

$failed = 0;

function chargx_redact_assert( $ok, $label ) {
    global $failed;
    if ( $ok ) {
        echo "ok  $label\n";
        return;
    }
    $failed++;
    echo "FAIL  $label\n";
}

$payload = array(
    'amount'         => '50.00',
    'currency'       => 'usd',
    'opaqueData'     => array( 'dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT', 'dataValue' => 'tok_secret' ),
    'customer'       => array(
        'name'  => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+15551212',
    ),
    'billingAddress' => array(
        'street'      => '1 Main St',
        'city'        => 'Austin',
        'zipCode'     => '78701',
        'countryCode' => 'US',
    ),
    'orderId'        => '125',
);

$redacted = ChargX_Logger::redact( $payload );

chargx_redact_assert( '50.00' === $redacted['amount'], 'keeps amount' );
chargx_redact_assert( '125' === $redacted['orderId'], 'keeps order id' );
chargx_redact_assert( 'US' === $redacted['billingAddress']['countryCode'], 'keeps country code' );
chargx_redact_assert( ChargX_Logger::MASK === $redacted['opaqueData'], 'masks opaqueData wholesale' );
chargx_redact_assert( ChargX_Logger::MASK === $redacted['customer']['email'], 'masks customer email' );
chargx_redact_assert( ChargX_Logger::MASK === $redacted['customer']['name'], 'masks customer name' );
chargx_redact_assert( ChargX_Logger::MASK === $redacted['customer']['phone'], 'masks customer phone' );
chargx_redact_assert( ChargX_Logger::MASK === $redacted['billingAddress']['street'], 'masks street' );
chargx_redact_assert( ChargX_Logger::MASK === $redacted['billingAddress']['city'], 'masks city' );
chargx_redact_assert( ChargX_Logger::MASK === $redacted['billingAddress']['zipCode'], 'masks zip' );

$json = ChargX_Logger::encode( $payload );
chargx_redact_assert( false === strpos( $json, 'jane@example.com' ), 'encode drops raw email' );
chargx_redact_assert( false === strpos( $json, 'tok_secret' ), 'encode drops opaque token' );
chargx_redact_assert( false !== strpos( $json, '50.00' ), 'encode keeps amount' );

$url = 'https://shop.example/?wc-api=ok&order_id=125&key=wc_order_secret&email=jane@example.com&phone-number=+15551212';
$safe_url = ChargX_Logger::redact_url( $url );
chargx_redact_assert( false === strpos( $safe_url, 'jane@example.com' ), 'url drops email' );
chargx_redact_assert( false === strpos( $safe_url, 'wc_order_secret' ), 'url drops order key' );
chargx_redact_assert( false === strpos( $safe_url, '+15551212' ), 'url drops phone' );
chargx_redact_assert( false !== strpos( $safe_url, 'order_id=125' ), 'url keeps order_id' );

$line = ChargX_Logger::redact_string( 'pay with sk_live_abc123 and jane@example.com' );
chargx_redact_assert( false === strpos( $line, 'sk_live_abc123' ), 'string drops live secret key' );
chargx_redact_assert( false === strpos( $line, 'jane@example.com' ), 'string drops email' );
chargx_redact_assert( false !== strpos( $line, 'sk_live_***' ), 'string keeps key prefix' );

if ( $failed > 0 ) {
    echo "\n$failed failed\n";
    exit( 1 );
}

echo "\nall passed\n";
exit( 0 );
