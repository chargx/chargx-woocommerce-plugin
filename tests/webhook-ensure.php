<?php
/**
 * CLI checks for webhook registration after settings changes (no WordPress required).
 *
 * WordPress options, the WooCommerce settings API, and the ChargX Admin API are
 * replaced with in-memory stubs. Each Secret API key belongs to one store, and
 * each store has its own webhook endpoints, like the real Admin API.
 *
 * Run: php tests/webhook-ensure.php
 */
define( 'ABSPATH', true );

$GLOBALS['chargx_test_options'] = array();
$GLOBALS['chargx_test_logs']    = array();

function get_option( $key, $default = false ) {
    return array_key_exists( $key, $GLOBALS['chargx_test_options'] ) ? $GLOBALS['chargx_test_options'][ $key ] : $default;
}

function update_option( $key, $value, $autoload = true ) {
    $GLOBALS['chargx_test_options'][ $key ] = $value;
    return true;
}

function add_query_arg( $args, $url ) {
    return $url . '?' . http_build_query( $args );
}

function home_url( $path = '/' ) {
    return 'https://shop.example' . $path;
}

function untrailingslashit( $value ) {
    return rtrim( $value, '/\\' );
}

function trailingslashit( $value ) {
    return untrailingslashit( $value ) . '/';
}

function __( $text, $domain = 'default' ) {
    return $text;
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
}

function is_wp_error( $thing ) {
    return $thing instanceof WP_Error;
}

class WP_Error {
    protected $message;

    public function __construct( $code = '', $message = '', $data = '' ) {
        $this->message = $message;
    }

    public function get_error_message() {
        return $this->message;
    }
}

class WC_Logger {
    public function log( $level, $message, $context = array() ) {
        $GLOBALS['chargx_test_logs'][] = $message;
    }
}

function wc_get_logger() {
    return new WC_Logger();
}

/**
 * The parts of WC_Settings_API / WC_Payment_Gateway the gateway relies on.
 * process_admin_options() follows WooCommerce: it updates $this->settings and
 * the stored option, and leaves the gateway's own properties untouched.
 */
abstract class WC_Payment_Gateway {
    public $id;
    public $settings = array();
    public $form_fields = array();
    public $supports = array();
    public $title;
    public $description;
    public $enabled;
    public $method_title;
    public $method_description;
    public $has_fields;

    public function get_option_key() {
        return 'woocommerce_' . $this->id . '_settings';
    }

    public function init_settings() {
        $this->settings = get_option( $this->get_option_key(), null );
        if ( ! is_array( $this->settings ) ) {
            $this->settings = array();
            foreach ( $this->form_fields as $key => $field ) {
                $this->settings[ $key ] = isset( $field['default'] ) ? $field['default'] : '';
            }
        }
    }

    public function get_option( $key, $empty_value = null ) {
        if ( empty( $this->settings ) ) {
            $this->init_settings();
        }
        if ( ! isset( $this->settings[ $key ] ) ) {
            $this->settings[ $key ] = isset( $this->form_fields[ $key ]['default'] ) ? $this->form_fields[ $key ]['default'] : '';
        }
        if ( ! is_null( $empty_value ) && '' === $this->settings[ $key ] ) {
            $this->settings[ $key ] = $empty_value;
        }
        return $this->settings[ $key ];
    }

    public function process_admin_options() {
        $this->init_settings();
        foreach ( $this->form_fields as $key => $field ) {
            if ( 'title' === $field['type'] ) {
                continue;
            }
            if ( 'checkbox' === $field['type'] ) {
                $this->settings[ $key ] = ! empty( $GLOBALS['chargx_test_post'][ $key ] ) ? 'yes' : 'no';
            } else {
                $this->settings[ $key ] = isset( $GLOBALS['chargx_test_post'][ $key ] ) ? (string) $GLOBALS['chargx_test_post'][ $key ] : '';
            }
        }
        return update_option( $this->get_option_key(), $this->settings );
    }
}

require dirname( __DIR__ ) . '/includes/class-chargx-logger.php';
require dirname( __DIR__ ) . '/includes/class-chargx-api-client.php';
require dirname( __DIR__ ) . '/includes/class-chargx-webhook.php';
require dirname( __DIR__ ) . '/includes/class-wc-gateway-chargx-base.php';
require dirname( __DIR__ ) . '/includes/class-wc-gateway-chargx-card.php';

/**
 * In-memory Admin API. Records the Secret API key each call was made with.
 */
class ChargX_Test_API extends ChargX_API_Client {
    /** @var array<string,string> secret key => store id */
    public $key_store = array(
        'sk_test_store_a_1' => 'store_a',
        'sk_test_store_a_2' => 'store_a',
        'sk_test_store_b'   => 'store_b',
        'sk_live_store_l'   => 'store_l',
    );
    /** @var array<string, array<int, array>> store id => endpoints */
    public $endpoints = array();
    /** @var array<int, array{0:string,1:string}> [method, secret key] */
    public $calls = array();
    protected $next_id = 1;

    public function __construct() {
        parent::__construct( 'https://api.example', '', '', false );
    }

    protected function store() {
        return isset( $this->key_store[ $this->secret_key ] ) ? $this->key_store[ $this->secret_key ] : null;
    }

    public function get_webhooks() {
        $this->calls[] = array( 'get', $this->secret_key );
        $store = $this->store();
        if ( null === $store ) {
            return new WP_Error( 'chargx_no_secret', 'ChargX secret key is missing.' );
        }
        $list = isset( $this->endpoints[ $store ] ) ? $this->endpoints[ $store ] : array();
        return array( 'webhook_endpoints' => array_values( $list ) );
    }

    public function create_webhook( $url, $name = 'WOO', $events = array(), $enabled = true ) {
        $this->calls[] = array( 'create', $this->secret_key );
        $id     = 'we_' . $this->next_id;
        $secret = 'whsec_' . $this->next_id;
        $this->next_id++;
        $this->endpoints[ $this->store() ][ $id ] = array( 'id' => $id, 'url' => $url, 'events' => $events, 'enabled' => $enabled, 'secret' => $secret );
        return array( 'webhook_endpoint' => array( 'id' => $id ), 'secret' => $secret );
    }

    public function rotate_webhook_secret( $id ) {
        $this->calls[] = array( 'rotate', $this->secret_key );
        $secret = 'whsec_rotated_' . $this->next_id;
        $this->next_id++;
        $this->endpoints[ $this->store() ][ $id ]['secret'] = $secret;
        return array( 'secret' => $secret );
    }

    public function update_webhook( $id, $fields ) {
        $this->calls[] = array( 'update', $this->secret_key, $fields );
        $this->endpoints[ $this->store() ][ $id ] = array_merge( $this->endpoints[ $this->store() ][ $id ], $fields );
        return array();
    }

    public function call_names() {
        return array_map( function ( $call ) {
            return $call[0];
        }, $this->calls );
    }
}

class ChargX_Test_Gateway extends WC_Gateway_ChargX_Card {
    public function use_api( $api ) {
        $this->api_client = $api;
    }
}

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

/**
 * Start from a fresh site. $settings are already saved before the request.
 */
function chargx_reset( $settings = null ) {
    $GLOBALS['chargx_test_options'] = array();
    $GLOBALS['chargx_test_logs']    = array();
    if ( null !== $settings ) {
        update_option( 'woocommerce_chargx_card_settings', array_merge( chargx_settings( array() ), $settings ) );
    }
}

function chargx_settings( $overrides ) {
    return array_merge(
        array(
            'enabled'              => 'yes',
            'testmode'             => 'yes',
            'publishable_key'      => '',
            'secret_key'           => '',
            'test_publishable_key' => '',
            'test_secret_key'      => '',
            'api_endpoint'         => 'https://api.example',
            'debug'                => 'yes',
        ),
        $overrides
    );
}

/**
 * A new request: the gateway is constructed from the saved settings.
 */
function chargx_request( $api ) {
    $gateway = new ChargX_Test_Gateway();
    $gateway->use_api( $api );
    return $gateway;
}

/**
 * Settings form submit: the gateway is constructed from the old settings, then saves the new ones.
 */
function chargx_save( $api, $settings ) {
    $GLOBALS['chargx_test_post'] = chargx_settings( $settings );
    $gateway                     = chargx_request( $api );
    $gateway->process_admin_options();
    return $gateway;
}

function chargx_row( $mode ) {
    return ChargX_Webhook::get_for_mode( $mode );
}

function chargx_logged( $needle ) {
    foreach ( $GLOBALS['chargx_test_logs'] as $line ) {
        if ( false !== strpos( $line, $needle ) ) {
            return true;
        }
    }
    return false;
}

// First save of the keys registers the webhook in the same request, with the new key.
chargx_reset();
$api = new ChargX_Test_API();
chargx_save( $api, array( 'test_secret_key' => 'sk_test_store_a_1' ) );
$row = chargx_row( 'test' );
chargx_assert( array( 'get', 'create' ) === $api->call_names(), 'first save creates the endpoint' );
chargx_assert( 'sk_test_store_a_1' === $api->calls[0][1], 'first save calls the API with the key just saved' );
chargx_assert( isset( $api->endpoints['store_a'][ $row['id'] ] ), 'endpoint is created in the store of the key' );
chargx_assert( $api->endpoints['store_a'][ $row['id'] ]['secret'] === $row['secret'], 'stored secret is the endpoint secret' );
chargx_assert( ChargX_Webhook::key_fingerprint( 'sk_test_store_a_1' ) === $row['key'], 'stored row has the key fingerprint' );
chargx_assert(
    false === strpos( serialize( $GLOBALS['chargx_test_options']['chargx_wc_webhooks'] ), 'sk_test_store_a_1' ),
    'the Secret API key itself is not stored with the webhook'
);

// Later requests with the same settings make no API calls and log nothing.
$api->calls                   = array();
$GLOBALS['chargx_test_logs'] = array();
chargx_assert( true === chargx_request( $api )->ensure_webhook(), 'storefront request reports the webhook as registered' );
chargx_assert( array() === $api->calls, 'storefront request makes no API calls' );
chargx_assert( array() === $GLOBALS['chargx_test_logs'], 'storefront request logs nothing' );

// Saving again without changes only looks the endpoint up and keeps the secret.
chargx_save( $api, array( 'test_secret_key' => 'sk_test_store_a_1' ) );
chargx_assert( array( 'get' ) === $api->call_names(), 'unchanged save only looks the endpoint up' );
chargx_assert( chargx_row( 'test' ) === $row, 'unchanged save keeps the endpoint and its secret' );
chargx_assert( chargx_logged( 'reason=settings saved' ) && chargx_logged( 'kept endpoint ' . $row['id'] ), 'unchanged save logs what it checked' );

// ChargX disabled the endpoint after failed deliveries: a storefront request does not
// notice (no API call), the next settings save enables it again without rotating.
$api->endpoints['store_a'][ $row['id'] ]['enabled'] = false;
$api->calls = array();
chargx_request( $api )->ensure_webhook();
chargx_assert( array() === $api->calls, 'storefront request does not check whether the endpoint is enabled' );
chargx_save( $api, array( 'test_secret_key' => 'sk_test_store_a_1' ) );
chargx_assert( array( 'get', 'update' ) === $api->call_names(), 'save enables a disabled endpoint without rotating' );
chargx_assert( array( 'enabled' => true ) === $api->calls[1][2], 'only enabled is sent when the events are already subscribed' );
chargx_assert( true === $api->endpoints['store_a'][ $row['id'] ]['enabled'], 'endpoint is enabled again' );
chargx_assert( chargx_row( 'test' ) === $row, 'enabling keeps the stored secret' );
chargx_assert( chargx_logged( 'enabled endpoint ' . $row['id'] ), 'enabling is logged' );
$api->endpoints['store_a'][ $row['id'] ]['enabled'] = true;

// Keys from another store: the webhook is created in that store.
$old_id = $row['id'];
$api->calls = array();
chargx_save( $api, array( 'test_secret_key' => 'sk_test_store_b' ) );
$row = chargx_row( 'test' );
chargx_assert( array( 'get', 'create' ) === $api->call_names(), 'key from another store creates a new endpoint' );
chargx_assert( 'sk_test_store_b' === $api->calls[0][1], 'new store is looked up with the new key' );
chargx_assert( isset( $api->endpoints['store_b'][ $row['id'] ] ) && $row['id'] !== $old_id, 'stored endpoint now belongs to the new store' );
chargx_assert( $api->endpoints['store_b'][ $row['id'] ]['secret'] === $row['secret'], 'stored secret now signs the new store endpoint' );
chargx_assert( chargx_logged( 'reason=secret API key changed' ), 'log says the key changed' );

// store1 -> store2 -> store1: the store1 endpoint is reused, its secret rotated
// (the stored row now holds store2's endpoint), and it is enabled again if ChargX
// disabled it while deliveries were failing.
foreach ( array( 'sk_test_store_a_1' => 'same key', 'sk_test_store_a_2' => 'new key of store1' ) as $return_key => $variant ) {
    chargx_reset();
    $api = new ChargX_Test_API();
    chargx_save( $api, array( 'test_secret_key' => 'sk_test_store_a_1' ) );
    $first = chargx_row( 'test' );
    chargx_save( $api, array( 'test_secret_key' => 'sk_test_store_b' ) );
    $api->endpoints['store_a'][ $first['id'] ]['enabled'] = false;
    $api->calls = array();
    chargx_save( $api, array( 'test_secret_key' => $return_key ) );
    $back = chargx_row( 'test' );
    chargx_assert( array( 'get', 'update', 'rotate' ) === $api->call_names(), "back to store1 ($variant): looked up, enabled, rotated" );
    chargx_assert( $first['id'] === $back['id'] && $first['secret'] !== $back['secret'], "back to store1 ($variant): same endpoint, new secret" );
    chargx_assert( $api->endpoints['store_a'][ $first['id'] ]['secret'] === $back['secret'], "back to store1 ($variant): stored secret signs the endpoint" );
    chargx_assert( true === $api->endpoints['store_a'][ $first['id'] ]['enabled'], "back to store1 ($variant): endpoint enabled again" );
    chargx_assert( 1 === count( $api->endpoints['store_a'] ), "back to store1 ($variant): no second endpoint in store1" );
}

// Another key of the same store keeps the endpoint and its secret.
chargx_reset();
$api = new ChargX_Test_API();
chargx_save( $api, array( 'test_secret_key' => 'sk_test_store_a_1' ) );
$before     = chargx_row( 'test' );
$api->calls = array();
chargx_save( $api, array( 'test_secret_key' => 'sk_test_store_a_2' ) );
$after = chargx_row( 'test' );
chargx_assert( array( 'get' ) === $api->call_names(), 'new key of the same store only looks the endpoint up' );
chargx_assert( $before['id'] === $after['id'] && $before['secret'] === $after['secret'], 'same store keeps endpoint id and secret' );
chargx_assert( ChargX_Webhook::key_fingerprint( 'sk_test_store_a_2' ) === $after['key'], 'same store stores the new key fingerprint' );

// Rows saved by older versions have no fingerprint: adopt it without rotating.
chargx_reset( chargx_settings( array( 'test_secret_key' => 'sk_test_store_a_1' ) ) );
$api = new ChargX_Test_API();
$api->endpoints['store_a']['we_old'] = array( 'id' => 'we_old', 'url' => ChargX_Webhook::webhook_url(), 'events' => WC_Gateway_ChargX_Card::WEBHOOK_EVENTS, 'secret' => 'whsec_old' );
update_option( 'chargx_wc_webhooks', array( 'test' => array( 'id' => 'we_old', 'secret' => 'whsec_old', 'url' => ChargX_Webhook::webhook_url(), 'events' => WC_Gateway_ChargX_Card::WEBHOOK_EVENTS ) ) );
chargx_assert( true === chargx_request( $api )->ensure_webhook(), 'legacy row is accepted' );
$row = chargx_row( 'test' );
chargx_assert( array( 'get' ) === $api->call_names(), 'legacy row is not rotated' );
chargx_assert( 'whsec_old' === $row['secret'] && ChargX_Webhook::key_fingerprint( 'sk_test_store_a_1' ) === $row['key'], 'legacy row keeps its secret and gets the fingerprint' );

// The store already has an endpoint for this URL, but this site has no secret for it.
chargx_reset( chargx_settings( array( 'test_secret_key' => 'sk_test_store_a_1' ) ) );
$api = new ChargX_Test_API();
$api->endpoints['store_a']['we_manual'] = array( 'id' => 'we_manual', 'url' => ChargX_Webhook::webhook_url(), 'events' => array( 'payment.succeeded' ), 'secret' => 'whsec_manual' );
chargx_request( $api )->ensure_webhook();
$row = chargx_row( 'test' );
chargx_assert( array( 'get', 'update', 'rotate' ) === $api->call_names(), 'existing endpoint without a stored secret is subscribed and rotated' );
chargx_assert( array( 'events' => WC_Gateway_ChargX_Card::WEBHOOK_EVENTS ) === $api->calls[1][2], 'only the events are sent for an enabled endpoint' );
chargx_assert( 'we_manual' === $row['id'] && $api->endpoints['store_a']['we_manual']['secret'] === $row['secret'], 'rotated secret is stored' );

// Turning test mode off on save registers the live endpoint with the live key.
chargx_reset();
$api = new ChargX_Test_API();
chargx_save( $api, array( 'test_secret_key' => 'sk_test_store_a_1', 'secret_key' => 'sk_live_store_l' ) );
$test_row   = chargx_row( 'test' );
$api->calls = array();
chargx_save( $api, array( 'testmode' => '', 'test_secret_key' => 'sk_test_store_a_1', 'secret_key' => 'sk_live_store_l' ) );
$live_row = chargx_row( 'live' );
chargx_assert( 'sk_live_store_l' === $api->calls[0][1], 'switching to live uses the live key on the same save' );
chargx_assert( isset( $api->endpoints['store_l'][ $live_row['id'] ] ), 'live endpoint is created in the live store' );
chargx_assert( chargx_row( 'test' ) === $test_row, 'test endpoint is kept when switching to live' );

// No Secret API key for the current mode: nothing is called and the reason is logged.
chargx_reset();
$api = new ChargX_Test_API();
chargx_save( $api, array( 'secret_key' => 'sk_live_store_l' ) );
chargx_assert( array() === $api->calls, 'missing key for the current mode makes no API calls' );
chargx_assert( chargx_logged( 'ensure_webhook skipped: no Secret API key for test mode' ), 'missing key for the current mode is logged' );

// The webhook URL changed (site moved): the endpoint registered for the old URL
// is left alone and a new one is registered for the current URL.
chargx_reset();
$api = new ChargX_Test_API();
chargx_save( $api, array( 'test_secret_key' => 'sk_test_store_a_1' ) );
$old_url               = 'https://old.example/?wc-api=wc_gateway_chargx_card_success_url_webhook';
$stored                = get_option( 'chargx_wc_webhooks' );
$stored['test']['url'] = $old_url;
update_option( 'chargx_wc_webhooks', $stored );
$api->endpoints['store_a'][ $stored['test']['id'] ]['url'] = $old_url;
$api->calls = array();
chargx_request( $api )->ensure_webhook();
chargx_assert( array( 'get', 'create' ) === $api->call_names(), 'changed webhook URL registers a new endpoint' );
chargx_assert( ChargX_Webhook::webhook_url() === chargx_row( 'test' )['url'], 'stored URL is the current one' );

if ( $failed > 0 ) {
    echo "\n$failed failed\n";
    exit( 1 );
}

echo "\nall passed\n";
exit( 0 );
