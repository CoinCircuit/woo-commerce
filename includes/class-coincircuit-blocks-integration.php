<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class CoinCircuit_Blocks_Integration extends AbstractPaymentMethodType {

	protected $name = 'coincircuit';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_coincircuit_settings', [] );
	}

	public function is_active() {
		return ! empty( $this->settings['enabled'] ) && $this->settings['enabled'] === 'yes';
	}

	public function get_payment_method_script_handles() {
		$asset_url = plugins_url( 'assets/js/blocks-checkout.js', COINCIRCUIT_PLUGIN_FILE );

		wp_register_script(
			'coincircuit-blocks-checkout',
			$asset_url,
			[ 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ],
			COINCIRCUIT_VERSION,
			true
		);

		return [ 'coincircuit-blocks-checkout' ];
	}

	public function get_payment_method_data() {
		$img_base = plugins_url( 'assets/img/', COINCIRCUIT_PLUGIN_FILE );

		return [
			'title'       => $this->get_setting( 'title' ) ?: __( 'CoinCircuit', 'coincircuit' ),
			'description' => $this->get_setting( 'description' ) ?: __( 'Pay securely with crypto via CoinCircuit.', 'coincircuit' ),
			'icon'        => $img_base . 'coincircuit-icon.png',
			'cryptoIcons' => [
				[ 'src' => $img_base . 'btc.svg', 'alt' => 'BTC' ],
				[ 'src' => $img_base . 'eth.svg', 'alt' => 'ETH' ],
				[ 'src' => $img_base . 'usdt.svg', 'alt' => 'USDT' ],
				[ 'src' => $img_base . 'usdc.svg', 'alt' => 'USDC' ],
			],
			'supports'    => [ 'products' ],
		];
	}
}
