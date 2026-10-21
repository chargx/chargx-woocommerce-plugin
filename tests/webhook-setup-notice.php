<?php
/**
 * CLI checks for the admin notice shown when the ChargX webhook is not working (no WordPress required).
 *
 * Run: php tests/webhook-setup-notice.php
 */
define( 'ABSPATH', true );

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

$no_error = array();
$live_401 = array( 'mode' => 'live', 'message' => 'Unauthorized', 'status' => 401 );
$live_403 = array( 'mode' => 'live', 'message' => 'Merchant is inactive', 'status' => 403 );
$live_400 = array( 'mode' => 'live', 'message' => "Field 'events, 1' is required", 'status' => 400 );
$live_net = array( 'mode' => 'live', 'message' => 'cURL error 28', 'status' => 0 );

chargx_assert( 'missing_key' === ChargX_Webhook::setup_notice( 'live', false, false, $no_error ), 'no secret key' );
chargx_assert( 'missing_key' === ChargX_Webhook::setup_notice( 'live', false, true, $live_401 ), 'no secret key wins over a stored error' );
chargx_assert( 'rejected_key' === ChargX_Webhook::setup_notice( 'live', true, false, $live_401 ), '401 means the key was rejected' );
chargx_assert( 'rejected_key' === ChargX_Webhook::setup_notice( 'live', true, false, $live_403 ), '403 means the key was rejected' );
chargx_assert( 'setup_failed' === ChargX_Webhook::setup_notice( 'live', true, false, $live_400 ), 'other API error' );
chargx_assert( 'setup_failed' === ChargX_Webhook::setup_notice( 'live', true, false, $live_net ), 'network error' );
chargx_assert( 'not_registered' === ChargX_Webhook::setup_notice( 'test', true, false, $live_401 ), 'error from the other mode is ignored' );
chargx_assert( 'not_registered' === ChargX_Webhook::setup_notice( 'live', true, false, $no_error ), 'key set but webhook not registered yet' );
chargx_assert( '' === ChargX_Webhook::setup_notice( 'live', true, true, $no_error ), 'working webhook shows no notice' );

if ( $failed ) {
    echo "\n$failed check(s) failed\n";
    exit( 1 );
}
echo "\nAll checks passed\n";
