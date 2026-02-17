<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ChargX Bank gateway.
 *
 * Displays a single "Pay With Bank" button. When FinGrid is enabled, integrates with
 * FinGrid (https://developer.fingrid.io/api/overview) for bank-to-bank payments.
 * Otherwise uses ChargX redirect flow.
 */
class WC_Gateway_ChargX_Bank extends WC_Gateway_ChargX_Base {

    /**
     * FinGrid API client.
     *
     * @var FinGrid_API_Client|null
     */
    protected $fingrid_client;

    public function __construct() {
        $this->id                 = 'chargx_bank';
        $this->method_title       = __( 'ChargX – Bank', 'chargx-woocommerce' );
        $this->method_description = __( 'Pay with your bank via ChargX or FinGrid.', 'chargx-woocommerce' );
        $this->has_fields         = true;

        parent::__construct();

        add_action( 'woocommerce_api_wc_gateway_chargx_bank', array( $this, 'handle_return' ) );
        add_action( 'woocommerce_api_wc_gateway_chargx_bank_fingrid', array( $this, 'handle_fingrid_connect' ) );            
    }

    /**
     * Whether FinGrid integration is enabled.
     *
     * @return bool
     */
    public function is_fingrid_enabled() {
        return 'yes' === $this->get_option( 'fingrid_enabled', 'no' );
    }

    /**
     * Get FinGrid API client.
     *
     * @return FinGrid_API_Client|null
     */
    protected function get_fingrid_client() {
        if ( $this->fingrid_client instanceof FinGrid_API_Client ) {
            return $this->fingrid_client;
        }
        $api_url = $this->get_option( 'fingrid_api_url', '' );
        $client_id = $this->get_option( 'fingrid_client_id', '' );
        $client_secret = $this->get_option( 'fingrid_client_secret', '' );
        if ( empty( $api_url ) || empty( $client_id ) || empty( $client_secret ) ) {
            return null;
        }
        $this->fingrid_client = new FinGrid_API_Client( $api_url, $client_id, $client_secret );
        return $this->fingrid_client;
    }

    /**
     * Extra settings: FinGrid and Bank-specific.
     */
    public function init_form_fields() {
        parent::init_form_fields();

        if ( isset( $this->form_fields['title'] ) ) {
            $this->form_fields['title']['default'] = __( 'Pay With Bank', 'chargx-woocommerce' );
        }
        if ( isset( $this->form_fields['description'] ) ) {
            $this->form_fields['description']['default'] = __( 'Pay securely with your bank account. You will be redirected to complete the payment.', 'chargx-woocommerce' );
        }

        $fingrid_fields = array(
            'fingrid_section' => array(
                'title'       => __( 'FinGrid (Bank) Integration', 'chargx-woocommerce' ),
                'type'        => 'title',
                'description' => __( 'Optional: Use FinGrid for instant bank-to-bank payments. See https://developer.fingrid.io/api/overview', 'chargx-woocommerce' ),
            ),
            'fingrid_enabled' => array(
                'title'   => __( 'Use FinGrid', 'chargx-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable FinGrid bank connection flow (link_token → bank_token → payment)', 'chargx-woocommerce' ),
                'default' => 'no',
            ),
            'fingrid_api_url' => array(
                'title'       => __( 'FinGrid API URL', 'chargx-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'e.g. https://production.cabbagepay.com', 'chargx-woocommerce' ),
                'default'     => 'https://production.cabbagepay.com',
                'desc_tip'    => true,
            ),
            'fingrid_client_id' => array(
                'title'   => __( 'FinGrid Client ID', 'chargx-woocommerce' ),
                'type'    => 'text',
                'default' => '',
            ),
            'fingrid_client_secret' => array(
                'title'   => __( 'FinGrid Client Secret', 'chargx-woocommerce' ),
                'type'    => 'password',
                'default' => '',
            ),
        );

        $this->form_fields = array_merge( $this->form_fields, $fingrid_fields );
    }

    /**
     * Payment fields: description and a single "Pay With Bank" button.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo '<p>' . wp_kses_post( $this->description ) . '</p>';
        }
        ?>
        <div id="wc-<?php echo esc_attr( $this->id ); ?>-bank-form" class="wc-chargx-bank-form wc-payment-form">
            <button type="submit" class="button alt chargx-bank-button" name="woocommerce_checkout_place_order" value="<?php esc_attr_e( 'Pay With Bank', 'chargx-woocommerce' ); ?>">
                <?php esc_html_e( 'Pay With Bank', 'chargx-woocommerce' ); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Process the payment: FinGrid flow or ChargX redirect.
     *
     * @param int $order_id
     * @return array|void
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wc_add_notice( __( 'Order not found.', 'chargx-woocommerce' ), 'error' );
            return;
        }

        if ( $this->is_fingrid_enabled() && $this->get_fingrid_client() ) {
            return $this->process_payment_fingrid( $order );
        }

        return $this->process_payment_chargx( $order );
    }

    /**
     * Redirect to FinGrid connect page (link_token → SDK → public_token → we exchange and pay).
     *
     * @param WC_Order $order
     * @return array
     */
    protected function process_payment_fingrid( $order ) {
        $this->log( 'process_payment_fingrid', 'info' );
            
        $connect_url = WC()->api_request_url( 'wc_gateway_chargx_bank_fingrid' );
        $connect_url = add_query_arg( array(
            'order_id' => $order->get_id(),
            'key'      => $order->get_order_key(),
        ), $connect_url );

        return array(
            'result'   => 'success',
            'redirect' => $connect_url,
        );
    }

    /**
     * ChargX redirect flow (create payment request, redirect to ChargX checkout).
     *
     * @param WC_Order $order
     * @return array|void
     */
    protected function process_payment_chargx( $order ) {
        $payment_redirect_success_url = $this->payment_redirect_success_url;
        if ( empty( $payment_redirect_success_url ) ) {
            $payment_redirect_success_url = WC()->api_request_url( 'wc_gateway_chargx_bank' );
        }
        $separator = ( strpos( $payment_redirect_success_url, '?' ) !== false ) ? '&' : '?';
        $payment_redirect_success_url .= $separator . 'order_id=' . $order->get_id();

        $api      = $this->get_api_client();
        $response = $api->create_payment_request( $order->get_total(), $order->get_currency(), 'bank', $payment_redirect_success_url );

        if ( is_wp_error( $response ) ) {
            wc_add_notice( __( 'Payment could not be started. Please try again.', 'chargx-woocommerce' ), 'error' );
            return;
        }

        $payment_request = isset( $response['payment_request'] ) ? $response['payment_request'] : array();
        $checkout_url    = isset( $payment_request['checkout_url'] ) ? $payment_request['checkout_url'] : '';

        if ( empty( $checkout_url ) ) {
            wc_add_notice( __( 'Payment could not be started. Please try again.', 'chargx-woocommerce' ), 'error' );
            return;
        }

        $checkout_url .= ( strpos( $checkout_url, '?' ) !== false ? '&' : '?' ) . 'success_url=' . rawurlencode( $payment_redirect_success_url );

        return array(
            'result'   => 'success',
            'redirect' => $checkout_url,
        );
    }

    /**
     * FinGrid connect page: GET shows link flow; POST receives public_token and completes payment.
     */
    public function handle_fingrid_connect() {
        $order_id = absint( isset( $_REQUEST['order_id'] ) ? $_REQUEST['order_id'] : 0 );
        $key      = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : '';

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_order_key() !== $key ) {
            wp_die( esc_html__( 'Invalid order.', 'chargx-woocommerce' ), 400 );
        }

        if ( $this->is_post_request() && ! empty( $_POST['fingrid_public_token'] ) ) {
            if ( ! isset( $_POST['fingrid_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fingrid_nonce'] ) ), 'chargx_fingrid_connect' ) ) {
                wp_die( esc_html__( 'Security check failed.', 'chargx-woocommerce' ), 403 );
            }
            $this->handle_fingrid_public_token( $order );
            return;
        }

        $this->render_fingrid_connect_page( $order );
    }

    /**
     * Check if current request is POST.
     *
     * @return bool
     */
    protected function is_post_request() {
        return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
    }

    /**
     * Handle submitted public_token: exchange for bank_token and create payment.
     *
     * @param WC_Order $order
     */
    protected function handle_fingrid_public_token( $order ) {
        $public_token = sanitize_text_field( wp_unslash( $_POST['fingrid_public_token'] ) );
        $client       = $this->get_fingrid_client();

        if ( ! $client ) {
            wc_add_notice( __( 'FinGrid is not configured.', 'chargx-woocommerce' ), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        $exchange = $client->exchange_public_token( $public_token );
        if ( is_wp_error( $exchange ) ) {
            $this->log( 'FinGrid exchange_public_token failed: ' . $exchange->get_error_message(), 'error' );
            wc_add_notice( __( 'Bank connection could not be completed. Please try again.', 'chargx-woocommerce' ), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        $bank_token = isset( $exchange['bank_token'] ) ? $exchange['bank_token'] : '';
        if ( empty( $bank_token ) ) {
            wc_add_notice( __( 'Invalid response from bank. Please try again.', 'chargx-woocommerce' ), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        $return_url = $this->get_return_url( $order );
        $payment    = $client->create_payment(
            $bank_token,
            $order->get_total(),
            $order->get_currency(),
            (string) $order->get_id(),
            $return_url
        );

        if ( is_wp_error( $payment ) ) {
            $this->log( 'FinGrid create_payment failed: ' . $payment->get_error_message(), 'error' );
            wc_add_notice( $payment->get_error_message(), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }

        $fingrid_order_id = isset( $payment['id'] ) ? $payment['id'] : ( isset( $payment['orderId'] ) ? $payment['orderId'] : '' );
        if ( $fingrid_order_id ) {
            $order->update_meta_data( '_fingrid_order_id', $fingrid_order_id );
        }
        $order->payment_complete( $fingrid_order_id ?: 'fingrid' );
        $order->save();

        WC()->cart->empty_cart();
        wp_safe_redirect( $return_url );
        exit;
    }

    /**
     * Render the FinGrid connect page: create link_token and output HTML + SDK script.
     *
     * @param WC_Order $order
     */
    protected function render_fingrid_connect_page( $order ) {
        $client = $this->get_fingrid_client();
        if ( ! $client ) {
            wp_die( esc_html__( 'FinGrid is not configured.', 'chargx-woocommerce' ), 400 );
        }

        $link_response = $client->create_link_token( array(
            'client_name'  => 'ChargX',
            'redirect_uri' => $this->get_return_url( $order ),
            'cust_email' => 'test@test.com',
            'theme_color' => '1A73E8',
        ) );

        if ( is_wp_error( $link_response ) ) {
            $this->log( 'FinGrid create_link_token failed: ' . $link_response->get_error_message(), 'error' );
            wp_die( esc_html__( 'Unable to start bank connection. Please try again.', 'chargx-woocommerce' ), 500 );
        }

  

        $link_token = isset( $link_response['link_token'] ) ? $link_response['link_token'] : '';
        if ( empty( $link_token ) ) {
            wp_die( esc_html__( 'Invalid link token from FinGrid.', 'chargx-woocommerce' ), 500 );
        }

        $use_sandbox = ( 'yes' === $this->testmode );
        $sdk_url = $use_sandbox
            ? 'https://cabbagepay.com/js/sandbox/cabbage.js'
            : 'https://cabbagepay.com/js/production/cabbage.js';
        $form_action = WC()->api_request_url( 'wc_gateway_chargx_bank_fingrid' );
        $form_action = add_query_arg( array(
            'order_id' => $order->get_id(),
            'key'      => $order->get_order_key(),
        ), $form_action );
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php esc_html_e( 'Pay With Bank', 'chargx-woocommerce' ); ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 2rem; max-width: 480px; margin: 0 auto; }
                .fingrid-loading { color: #666; }
                .fingrid-error { color: #b32d2e; margin-top: 1rem; }
                .fingrid-form { margin-top: 1.5rem; }
                button.button { padding: 12px 24px; font-size: 16px; cursor: pointer; background: #0073aa; color: #fff; border: none; border-radius: 4px; }
                button.button:disabled { opacity: 0.6; cursor: not-allowed; }
            </style>
        </head>
        <body>
            <p class="fingrid-loading" id="fingrid-message"><?php esc_html_e( 'Opening secure bank connection…', 'chargx-woocommerce' ); ?></p>
            <p class="fingrid-error" id="fingrid-error" style="display:none;"></p>

            <form id="fingrid-token-form" class="fingrid-form" action="<?php echo esc_url( $form_action ); ?>" method="post" style="display:none;">
                <?php wp_nonce_field( 'chargx_fingrid_connect', 'fingrid_nonce' ); ?>
                <input type="hidden" name="fingrid_public_token" id="fingrid-public-token" value="" />
            </form>

            <script src="<?php echo esc_url( $sdk_url ); ?>"></script>
            <script>
                (function() {
                    var linkToken = <?php echo wp_json_encode( $link_token ); ?>;
                    var form = document.getElementById('fingrid-token-form');
                    var input = document.getElementById('fingrid-public-token');
                    var message = document.getElementById('fingrid-message');
                    var errEl = document.getElementById('fingrid-error');

                    function showError(msg) {
                        message.style.display = 'none';
                        errEl.textContent = msg || '<?php echo esc_js( __( 'Something went wrong. Please try again.', 'chargx-woocommerce' ) ); ?>';
                        errEl.style.display = 'block';
                    }

                    window.addEventListener('message', function(event) {
                        try {
                            var data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
                            if (!data || !data.message) return;

                            if (data.message === 'success') {
                                var publicToken = data.public_token;
                                if (!publicToken) { showError(); return; }
                                input.value = publicToken;
                                message.textContent = '<?php echo esc_js( __( 'Bank connected. Completing payment…', 'chargx-woocommerce' ) ); ?>';
                                form.submit();
                            }

                            if (data.message === 'terminated') {
                                showError('<?php echo esc_js( __( 'Bank connection was cancelled. You can try again from checkout.', 'chargx-woocommerce' ) ); ?>');
                            }
                        } catch (e) {}
                    });

                    if (typeof cabbage !== 'undefined') {
                        cabbage.initializeGrid(linkToken);
                        cabbage.openGrid(linkToken);
                    } else {
                        showError('<?php echo esc_js( __( 'Cabbage SDK not loaded. Please check gateway SDK URLs.', 'chargx-woocommerce' ) ); ?>');
                    }
                })();
            </script>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * Handle return from ChargX after successful bank payment.
     */
    public function handle_return() {
        $order_id = absint( isset( $_GET['order_id'] ) ? $_GET['order_id'] : 0 );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_die( esc_html__( 'Invalid order.', 'chargx-woocommerce' ), 400 );
        }

        if ( ! empty( $_GET['chargx_order_id'] ) ) {
            $order->update_meta_data( '_chargx_order_id', sanitize_text_field( wp_unslash( $_GET['chargx_order_id'] ) ) );
        }
        if ( ! empty( $_GET['chargx_order_display_id'] ) ) {
            $order->update_meta_data( '_chargx_order_display_id', sanitize_text_field( wp_unslash( $_GET['chargx_order_display_id'] ) ) );
        }
        $order->save();

        if ( ! empty( $_GET['chargx_order_id'] ) ) {
            $order->payment_complete( sanitize_text_field( wp_unslash( $_GET['chargx_order_id'] ) ) );
        }

        wp_safe_redirect( $this->get_return_url( $order ) );
        exit;
    }
}
