<?php
/**
 * Plugin Name: ChargX Payment Gateway for WooCommerce
 * Description: ChargX payment gateway for WooCommerce (Credit Cards and Pay By Bank).
 * Author: ChargX
 * Version: 0.22.0
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
    require_once CHARGX_WC_PLUGIN_PATH . 'includes/class-wc-gateway-chargx-bank.php';

    // Register gateways.
    add_filter( 'woocommerce_payment_gateways', 'chargx_wc_register_gateways' );

    // Assets on checkout/pay page.
    add_action( 'wp_enqueue_scripts', 'chargx_wc_enqueue_assets' );

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

    // add 5% discount to the order for bank to bank transfer
    add_action( 'woocommerce_cart_calculate_fees', 'chargx_bank_transfer_discount' );
    // That hook injects JavaScript into the page footer to refresh the checkout when the payment method is changed
    add_action( 'wp_footer', 'chargx_refresh_checkout_on_payment_change' );
}
add_action( 'plugins_loaded', 'chargx_wc_init', 20 );

register_activation_hook(__FILE__, 'chargx_activate');

/**
 * Register ChargX gateways with WooCommerce.
 *
 * @param array $gateways
 * @return array
 */
function chargx_wc_register_gateways( $gateways ) {
    $gateways[] = 'WC_Gateway_ChargX_Card';
    $gateways[] = 'WC_Gateway_ChargX_Bank';

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
        'chargx-checkout-utils-js',
        CHARGX_WC_PLUGIN_URL . 'assets/js/chargx-checkout-utils.js',
        array(),
        CHARGX_WC_VERSION,
        true
    );

    wp_enqueue_script(
        'chargx-checkout-js',
        CHARGX_WC_PLUGIN_URL . 'assets/js/chargx-checkout.js',
        array( 'jquery', 'chargx-checkout-utils-js' ),
        CHARGX_WC_VERSION,
        true
    );

    // Load 3ds script.
    // TODO: maybe load it only if 3DS enabled?.
    wp_enqueue_script(
      'chargx-gateway-js',
      'https://secure.networkmerchants.com/js/v1/Gateway.js',
      array(),
      null,
      true // load in footer
    );

    // Get active gateways and their settings.
    $gateways = WC()->payment_gateways() ? WC()->payment_gateways->payment_gateways() : array();

    /** @var WC_Gateway_ChargX_Card|null $card_gateway */
    $card_gateway     = isset( $gateways['chargx_card'] ) ? $gateways['chargx_card'] : null;

    $cart_total = 0;
    if ( function_exists( 'WC' ) && WC()->cart ) {
        $cart_total = (float) WC()->cart->get_total( 'edit' );
    }

    $params = array(
        'ajax_url'           => admin_url( 'admin-ajax.php' ),
        'checkout_url'       => WC_AJAX::get_endpoint( 'checkout' ),
        'card_gateway_id'    => 'chargx_card',
        'is_checkout'        => is_checkout() ? 'yes' : 'no',
        'is_pay_for_order'   => is_checkout_pay_page() ? 'yes' : 'no',
        'cart_total'         => $cart_total,
        'currency'           => get_woocommerce_currency(),
        'card_publishable'   => $card_gateway ? $card_gateway->get_publishable_key() : '',
        'enable_3ds'        => 'no',
        '3ds_mount_element_selector' => '',
        'card_testmode'      => $card_gateway && 'yes' === $card_gateway->get_option( 'testmode', 'no' ) ? 'yes' : 'no',
        'payment_redirection_flow' => $card_gateway->payment_redirection_flow,
        'api_endpoint'       => $card_gateway ? $card_gateway->get_option( 'api_endpoint' ) : 'https://api.chargx.io',
        'i18n'               => array(
            'card_error'       => __( 'Unable to process your card. Please check the details and try again.', 'chargx-woocommerce' ),
            'card_required'    => __( 'Please fill in all required card fields.', 'chargx-woocommerce' ),
        ),
        'version'            => CHARGX_WC_VERSION,
    );

    wp_localize_script( 'chargx-checkout-js', 'chargx_wc_params', $params );
}

function chargx_bank_transfer_discount( $cart ) {
    //wc_get_logger()->info('chargx_bank_transfer_discount', ['source' => 'chargx-woocommerce']);

    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        // $this->log( 'chargx_bank_transfer_discount is_admin && ! defined( DOING_AJAX )', 'info' );
        return;
    }

    if ( ! WC()->session ) {
        // $this->log( 'chargx_bank_transfer_discount WC()->session not found', 'info' );
        return;
    }

    $chosen_payment_method = WC()->session->get( 'chosen_payment_method' );
    wc_get_logger()->info('chargx_bank_transfer_discount chosen_payment_method: ' . $chosen_payment_method, ['source' => 'chargx-woocommerce']);

    if ( $chosen_payment_method === 'chargx_bank' ) {

        // 5% from subtotal (products only)
        $discount = $cart->get_subtotal() * 0.05;
        // $this->log( 'chargx_bank_transfer_discount discount: ' . $discount, 'info' );

        if ( $discount > 0 ) {
            $cart->add_fee('5% Pay-By-Bank Discount', -$discount );
        }
    }
}

function chargx_refresh_checkout_on_payment_change() {
    if ( ! is_checkout() ) {
        return;
    }
    ?>
    <script type="text/javascript">
        jQuery(function($){
            $('form.checkout').on('change', 'input[name="payment_method"]', function(){
                $('body').trigger('update_checkout');
            });
        });
    </script>
    <?php
}

function chargx_activate() {
    wc_get_logger()->info('chargx_activate', ['source' => 'chargx-woocommerce']);

    // enable card and bank gateways by default
    $gateways = [
        'chargx_bank',
        'chargx_card',
    ];

    foreach ($gateways as $gateway) {

        $option_key = "woocommerce_{$gateway}_settings";

        if (!get_option($option_key)) {
            add_option($option_key, [
                'enabled' => 'yes'
            ]);
        }
    }
}

