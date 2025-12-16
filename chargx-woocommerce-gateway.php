<?php
/**
 * Plugin Name: ChargX Payment Gateway for WooCommerce
 * Description: Modern ChargX payment gateway for WooCommerce (Credit Cards + Apple/Google Pay, refunds, recurring).
 * Author: ChargX
 * Version: 0.18.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 4.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CHARGX_WC_VERSION', '1.1.0' );
define( 'CHARGX_WC_PLUGIN_FILE', __FILE__ );
define( 'CHARGX_WC_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'CHARGX_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Initialize the gateway after WooCommerce is loaded.
 */
function chargx_wc_init() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    // Includes.
    require_once CHARGX_WC_PLUGIN_PATH . 'includes/class-chargx-logger.php';
    require_once CHARGX_WC_PLUGIN_PATH . 'includes/class-chargx-api-client.php';
    require_once CHARGX_WC_PLUGIN_PATH . 'includes/class-wc-gateway-chargx-base.php';
    require_once CHARGX_WC_PLUGIN_PATH . 'includes/class-wc-gateway-chargx-card.php';
    require_once CHARGX_WC_PLUGIN_PATH . 'includes/class-wc-gateway-chargx-applepay.php';
    require_once CHARGX_WC_PLUGIN_PATH . 'includes/class-wc-gateway-chargx-googlepay.php';
    require_once CHARGX_WC_PLUGIN_PATH . 'includes/class-chargx-subscriptions-helper.php';

    // Register gateways.
    add_filter( 'woocommerce_payment_gateways', 'chargx_wc_register_gateways' );

    // Assets on checkout/pay page.
    add_action( 'wp_enqueue_scripts', 'chargx_wc_enqueue_assets' );

    // Apple Pay merchant validation endpoint.
    add_action( 'wp_ajax_chargx_applepay_validate_merchant', 'chargx_wc_applepay_validate_merchant' );
    add_action( 'wp_ajax_nopriv_chargx_applepay_validate_merchant', 'chargx_wc_applepay_validate_merchant' );

    // HPOS support
    // https://woocommerce.com/document/high-performance-order-storage/
    // https://webkul.com/blog/woocommerce-plugin-high-performance-order-storage-compatible/
    add_action('before_woocommerce_init', function() {
        // wc_get_logger()->info('before_woocommerce_init1', ['source' => 'chargx']);
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables', // HPOS feature name
                __FILE__,              // your main plugin file
                true                   // true = compatible, false = incompatible
            );
        }
    });
}
add_action( 'plugins_loaded', 'chargx_wc_init', 20 );

/**
 * Register ChargX gateways with WooCommerce.
 *
 * @param array $gateways
 * @return array
 */
function chargx_wc_register_gateways( $gateways ) {
    $gateways[] = 'WC_Gateway_ChargX_Card';
    $gateways[] = 'WC_Gateway_ChargX_ApplePay';
    $gateways[] = 'WC_Gateway_ChargX_GooglePay';

    return $gateways;
}

/**
 * Enqueue frontend assets.
 */
function chargx_wc_enqueue_assets() {
    if ( ! function_exists( 'is_checkout' ) ) {
        return;
    }

    if ( ! ( is_checkout() || is_checkout_pay_page() ) ) {
        return;
    }

    wp_enqueue_style(
        'chargx-checkout-css',
        CHARGX_WC_PLUGIN_URL . 'assets/css/chargx-checkout.css',
        array(),
        CHARGX_WC_VERSION
    );

    wp_enqueue_script(
        'chargx-checkout-js',
        CHARGX_WC_PLUGIN_URL . 'assets/js/chargx-checkout.js',
        array( 'jquery' ),
        CHARGX_WC_VERSION,
        true
    );

    // Get active gateways and their settings.
    $gateways = WC()->payment_gateways() ? WC()->payment_gateways->payment_gateways() : array();

    /** @var WC_Gateway_ChargX_Card|null $card_gateway */
    $card_gateway     = isset( $gateways['chargx_card'] ) ? $gateways['chargx_card'] : null;
    /** @var WC_Gateway_ChargX_ApplePay|null $apple_gateway */
    $apple_gateway    = isset( $gateways['chargx_applepay'] ) ? $gateways['chargx_applepay'] : null;
    /** @var WC_Gateway_ChargX_GooglePay|null $google_gateway */
    $google_gateway    = isset( $gateways['chargx_googlepay'] ) ? $gateways['chargx_googlepay'] : null;

    $cart_total = 0;
    if ( function_exists( 'WC' ) && WC()->cart ) {
        $cart_total = (float) WC()->cart->get_total( 'edit' );
    }

    $params = array(
        'ajax_url'           => admin_url( 'admin-ajax.php' ),
        'checkout_url'       => WC_AJAX::get_endpoint( 'checkout' ),
        'card_gateway_id'    => 'chargx_card',
        'apple_gateway_id'   => 'chargx_applepay',
        'google_gateway_id'  => 'chargx_googlepay',
        'is_checkout'        => is_checkout() ? 'yes' : 'no',
        'is_pay_for_order'   => is_checkout_pay_page() ? 'yes' : 'no',
        'cart_total'         => $cart_total,
        'currency'           => get_woocommerce_currency(),
        'card_publishable'   => $card_gateway ? $card_gateway->get_publishable_key() : '',
        'apple_publishable'  => $apple_gateway ? $apple_gateway->get_publishable_key() : '',
        'google_publishable' => $google_gateway ? $google_gateway->get_publishable_key() : '',
        'card_testmode'      => $card_gateway && 'yes' === $card_gateway->get_option( 'testmode', 'no' ) ? 'yes' : 'no',
        'apple_testmode'     => $apple_gateway && 'yes' === $apple_gateway->get_option( 'testmode', 'no' ) ? 'yes' : 'no',
        'google_testmode'    => $google_gateway && 'yes' === $google_gateway->get_option( 'testmode', 'no' ) ? 'yes' : 'no',
        'i18n'               => array(
            'card_error'       => __( 'Unable to process your card. Please check the details and try again.', 'chargx-woocommerce' ),
            'card_required'    => __( 'Please fill in all required card fields.', 'chargx-woocommerce' ),
            'apple_not_avail'  => __( 'Apple Pay is not available on this device or browser.', 'chargx-woocommerce' ),
            'apple_error'      => __( 'Apple Pay payment failed. Please try another payment method.', 'chargx-woocommerce' ),
            'google_not_avail'  => __( 'Google Pay is not available on this device or browser.', 'chargx-woocommerce' ),
            'google_error'      => __( 'Google Pay payment failed. Please try another payment method.', 'chargx-woocommerce' ),
        ),
        'version'            => CHARGX_WC_VERSION,
    );

    wp_localize_script( 'chargx-checkout-js', 'chargx_wc_params', $params );
}

/**
 * Ajax handler to validate Apple Pay merchant with Apple servers.
 *
 * NOTE: You MUST configure your merchant ID, cert and key files
 * in the Apple Pay gateway settings for this to work in production.
 */
function chargx_wc_applepay_validate_merchant() {
    if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
        wp_send_json_error( array( 'message' => 'WooCommerce not loaded' ), 400 );
    }

    $gateways = WC()->payment_gateways() ? WC()->payment_gateways->payment_gateways() : array();
    /** @var WC_Gateway_ChargX_ApplePay|null $apple_gateway */
    $apple_gateway = isset( $gateways['chargx_applepay'] ) ? $gateways['chargx_applepay'] : null;

    if ( ! $apple_gateway ) {
        wp_send_json_error( array( 'message' => 'Apple Pay gateway not available' ), 400 );
    }

    $validation_url = isset( $_POST['validationUrl'] ) ? esc_url_raw( wp_unslash( $_POST['validationUrl'] ) ) : '';

    if ( empty( $validation_url ) ) {
        wp_send_json_error( array( 'message' => 'Missing validation URL' ), 400 );
    }

    $session = $apple_gateway->validate_apple_pay_merchant( $validation_url );

    if ( is_wp_error( $session ) ) {
        wp_send_json_error( array(
            'message' => $session->get_error_message(),
        ), 400 );
    }

    wp_send_json_success( $session );
}

