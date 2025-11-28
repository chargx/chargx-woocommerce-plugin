<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lightweight ChargX logger wrapper around WC_Logger.
 */
class ChargX_Logger {

    /**
     * Log key.
     *
     * @var string
     */
    protected static $source = 'chargx-woocommerce';

    /**
     * Write debug message when debug is enabled in gateway.
     *
     * @param string $message
     * @param string $level
     */
    public static function log( $message, $level = 'info' ) {
        if ( ! class_exists( 'WC_Logger' ) ) {
            return;
        }

        $logger = wc_get_logger();
        $logger->log( $level, $message, array( 'source' => self::$source ) );
    }
}
