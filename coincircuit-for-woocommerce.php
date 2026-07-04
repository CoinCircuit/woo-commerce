<?php
/**
 * Plugin Name: CoinCircuit for WooCommerce
 * Plugin URI:  https://coincircuit.io
 * Description: Accept cryptocurrency payments via CoinCircuit in your WooCommerce store.
 * Version:     1.2.0
 * Author:      CoinCircuit
 * Author URI:  https://coincircuit.io
 * Text Domain: coincircuit
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COINCIRCUIT_VERSION', '1.2.0' );
define( 'COINCIRCUIT_PLUGIN_FILE', __FILE__ );
define( 'COINCIRCUIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

add_action( 'plugins_loaded', 'coincircuit_init', 11 );
add_action( 'woocommerce_blocks_loaded', 'coincircuit_register_blocks_integration' );

function coincircuit_init() {
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'coincircuit_woocommerce_missing_notice' );
		return;
	}

	require_once COINCIRCUIT_PLUGIN_DIR . 'includes/class-coincircuit-api.php';
	require_once COINCIRCUIT_PLUGIN_DIR . 'includes/class-coincircuit-gateway.php';
	require_once COINCIRCUIT_PLUGIN_DIR . 'includes/class-coincircuit-webhook.php';

	new CoinCircuit_Webhook();

	add_filter( 'woocommerce_payment_gateways', 'coincircuit_add_gateway' );
}

function coincircuit_register_blocks_integration() {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	require_once COINCIRCUIT_PLUGIN_DIR . 'includes/class-coincircuit-blocks-integration.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( $registry ) {
			$registry->register( new CoinCircuit_Blocks_Integration() );
		}
	);
}

function coincircuit_add_gateway( $gateways ) {
	$gateways[] = 'CoinCircuit_Gateway';
	return $gateways;
}

function coincircuit_woocommerce_missing_notice() {
	echo '<div class="notice notice-error"><p>';
	echo '<strong>' . esc_html__( 'CoinCircuit for WooCommerce', 'coincircuit' ) . '</strong> ';
	esc_html_e( 'requires WooCommerce to be installed and active.', 'coincircuit' );
	echo '</p></div>';
}

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
