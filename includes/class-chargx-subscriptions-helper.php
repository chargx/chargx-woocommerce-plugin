<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Helper for WooCommerce Subscriptions + ChargX.
 *
 * NOTE:
 * This is a minimal integration. It:
 * - Creates a ChargX subscription when the initial order contains subscriptions.
 * - Stores the ChargX subscription id as meta.
 * - Cancels ChargX subscription when Woo subscription is cancelled.
 */
class ChargX_Subscriptions_Helper {

    /**
     * Boot hooks.
     */
    public static function init() {
        if ( ! class_exists( 'WC_Subscriptions' ) ) {
            return;
        }

        // When a subscription payment is completed, create ChargX subscription if needed.
        add_action( 'woocommerce_subscription_payment_complete', array( __CLASS__, 'maybe_create_chargx_subscription' ), 10, 1 );

        // When subscription is cancelled, delete ChargX subscription.
        add_action( 'woocommerce_subscription_status_cancelled', array( __CLASS__, 'maybe_cancel_chargx_subscription' ), 10, 1 );
    }

    /**
     * Create ChargX subscription if missing.
     *
     * @param WC_Subscription $subscription
     */
    public static function maybe_create_chargx_subscription( $subscription ) {
        $existing = $subscription->get_meta( '_chargx_subscription_id', true );
        if ( $existing ) {
            return;
        }

        $parent_order = $subscription->get_parent();
        if ( ! $parent_order ) {
            return;
        }

        $payment_method = $parent_order->get_payment_method();

        if ( ! in_array( $payment_method, array( 'chargx_card', 'chargx_applepay' ), true ) ) {
            return;
        }

        $chargx_order_id = $parent_order->get_meta( '_chargx_order_id', true );
        $chargx_opaque   = $parent_order->get_meta( '_chargx_opaque_data', true );

        if ( empty( $chargx_opaque ) ) {
            return;
        }

        $opaque_data = json_decode( $chargx_opaque, true );
        if ( empty( $opaque_data ) || ! is_array( $opaque_data ) ) {
            return;
        }

        $gateway = null;
        if ( 'chargx_card' === $payment_method ) {
            $gateway = wc()->payment_gateways()->payment_gateways()['chargx_card'] ?? null;
        } elseif ( 'chargx_applepay' === $payment_method ) {
            $gateway = wc()->payment_gateways()->payment_gateways()['chargx_applepay'] ?? null;
        }

        if ( ! $gateway || ! method_exists( $gateway, 'get_api_client' ) ) {
            return;
        }

        $api = $gateway->get_api_client();

        $variant_id = apply_filters( 'chargx_subscription_variant_id', 'woocommerce-subscription-' . $subscription->get_id(), $subscription );

        $billing = array(
            'street'      => $parent_order->get_billing_address_1(),
            'unit'        => $parent_order->get_billing_address_2(),
            'city'        => $parent_order->get_billing_city(),
            'state'       => $parent_order->get_billing_state(),
            'zipCode'     => $parent_order->get_billing_postcode(),
            'countryCode' => $parent_order->get_billing_country(),
        );

        $payload = array(
            'variant_id' => $variant_id,
            'opaqueData' => $opaque_data,
            'customer'   => array(
                'email'    => $parent_order->get_billing_email(),
                'name'     => $parent_order->get_billing_first_name(),
                'lastName' => $parent_order->get_billing_last_name(),
                'phone'    => $parent_order->get_billing_phone(),
            ),
            'address'    => $billing,
        );

        $response = $api->create_subscription( $payload );

        if ( is_wp_error( $response ) ) {
            ChargX_Logger::log( 'Failed to create ChargX subscription: ' . $response->get_error_message(), 'error' );
            return;
        }

        if ( ! empty( $response['id'] ) ) {
            $subscription->update_meta_data( '_chargx_subscription_id', $response['id'] );
            $subscription->save();
        }
    }

    /**
     * Cancel ChargX subscription.
     *
     * @param WC_Subscription $subscription
     */
    public static function maybe_cancel_chargx_subscription( $subscription ) {
        $chargx_sub_id = $subscription->get_meta( '_chargx_subscription_id', true );
        if ( ! $chargx_sub_id ) {
            return;
        }

        $parent_order = $subscription->get_parent();
        if ( ! $parent_order ) {
            return;
        }

        $payment_method = $parent_order->get_payment_method();

        $gateway = null;
        if ( 'chargx_card' === $payment_method ) {
            $gateway = wc()->payment_gateways()->payment_gateways()['chargx_card'] ?? null;
        } elseif ( 'chargx_applepay' === $payment_method ) {
            $gateway = wc()->payment_gateways()->payment_gateways()['chargx_applepay'] ?? null;
        }

        if ( ! $gateway || ! method_exists( $gateway, 'get_api_client' ) ) {
            return;
        }

        $api      = $gateway->get_api_client();
        $response = $api->delete_subscription( $chargx_sub_id );

        if ( is_wp_error( $response ) ) {
            ChargX_Logger::log( 'Failed to cancel ChargX subscription: ' . $response->get_error_message(), 'error' );
        } else {
            $subscription->delete_meta_data( '_chargx_subscription_id' );
            $subscription->save();
        }
    }
}

// Boot helper.
add_action( 'plugins_loaded', array( 'ChargX_Subscriptions_Helper', 'init' ), 30 );
