<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoinCircuit_Gateway extends WC_Payment_Gateway {

	const SUPPORTED_CURRENCIES = [ 'NGN', 'USD' ];

	// A session is only reused when it will not expire within this window,
	// so the customer always has time to complete the payment.
	const REUSE_MIN_TTL = 60;

	// An untouched pending session is only reused while this fresh; older
	// ones are replaced. Partially paid sessions stay reusable for their
	// whole life so the funds already collected are never stranded.
	const REUSE_PENDING_MAX_AGE = 180;

	public function __construct() {
		$this->id                 = 'coincircuit';
		$this->has_fields         = false;
		$this->method_title       = __( 'CoinCircuit', 'coincircuit' );
		$this->method_description = __( 'Accept cryptocurrency payments via CoinCircuit.', 'coincircuit' );

		// Refunds are initiated from the CoinCircuit dashboard, where the
		// customer's wallet address is collected; the refund API requires it
		// and WooCommerce has no way to supply one. The refund.success
		// webhook updates the order when a refund completes.
		$this->supports = [ 'products' ];

		$this->icon = plugins_url( 'assets/img/coincircuit-icon.png', COINCIRCUIT_PLUGIN_FILE );

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->get_option( 'enabled' );
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'receipt_page' ] );
		add_action( 'admin_notices', [ $this, 'currency_admin_notice' ] );
	}

	public function init_form_fields() {
		$this->form_fields = [
			'enabled'        => [
				'title'   => __( 'Enable/Disable', 'coincircuit' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable CoinCircuit Payments', 'coincircuit' ),
				'default' => 'no',
			],
			'title'          => [
				'title'       => __( 'Title', 'coincircuit' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to customers at checkout.', 'coincircuit' ),
				'default'     => __( 'CoinCircuit', 'coincircuit' ),
				'desc_tip'    => true,
			],
			'description'    => [
				'title'       => __( 'Description', 'coincircuit' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown to customers at checkout.', 'coincircuit' ),
				'default'     => __( 'Pay securely with crypto via CoinCircuit.', 'coincircuit' ),
			],
			'environment'    => [
				'title'       => __( 'Environment', 'coincircuit' ),
				'type'        => 'select',
				'description' => __( 'Use Sandbox for testing before going live.', 'coincircuit' ),
				'default'     => 'production',
				'options'     => [
					'production' => __( 'Production', 'coincircuit' ),
					'sandbox'    => __( 'Sandbox', 'coincircuit' ),
				],
				'desc_tip'    => true,
			],
			'api_key'        => [
				'title'       => __( 'API Key', 'coincircuit' ),
				'type'        => 'password',
				'description' => __( 'Your CoinCircuit API key, found in the dashboard under API settings.', 'coincircuit' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'webhook_secret' => [
				'title'       => __( 'Webhook Secret', 'coincircuit' ),
				'type'        => 'password',
				'description' => __( 'Your CoinCircuit webhook signing secret, found in the dashboard under Webhook settings.', 'coincircuit' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'fee_paid_by'    => [
				'title'       => __( 'Network Fee Paid By', 'coincircuit' ),
				'type'        => 'select',
				'description' => __( 'Who covers the blockchain network fee.', 'coincircuit' ),
				'default'     => 'customer',
				'options'     => [
					'customer' => __( 'Customer', 'coincircuit' ),
					'merchant' => __( 'Merchant', 'coincircuit' ),
				],
				'desc_tip'    => true,
			],
		];
	}

	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( ! $this->is_valid_for_current_currency() ) {
			return false;
		}
		return ! empty( $this->get_option( 'api_key' ) );
	}

	public function is_valid_for_current_currency() {
		return in_array( get_woocommerce_currency(), self::SUPPORTED_CURRENCIES, true );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( empty( $this->get_option( 'api_key' ) ) ) {
			throw new Exception( __( 'CoinCircuit payment gateway is not configured. Please contact the store owner.', 'coincircuit' ) );
		}

		// The session is created here so API failures surface as a normal
		// checkout error, then the shopper lands on the order-pay page where
		// receipt_page() opens the embedded CoinCircuit checkout.
		$this->ensure_session( $order );

		WC()->cart->empty_cart();

		return [
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		];
	}

	/**
	 * Renders the payment step on the order-pay page: the embedded checkout
	 * opens automatically, the button reopens it if dismissed, and a full
	 * redirect covers browsers where the embed cannot load.
	 */
	public function receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || ! $order->needs_payment() ) {
			echo '<p>' . esc_html__( 'This order does not need payment.', 'coincircuit' ) . '</p>';
			return;
		}

		try {
			$session = $this->ensure_session( $order );
		} catch ( Exception $e ) {
			echo '<p>' . esc_html( $e->getMessage() ) . '</p>';
			return;
		}

		wp_enqueue_script(
			'coincircuit-checkout-embed',
			plugins_url( 'assets/js/checkout-embed.js', COINCIRCUIT_PLUGIN_FILE ),
			[],
			COINCIRCUIT_VERSION,
			true
		);

		$config = [
			'url'        => $session['url'],
			'successUrl' => $this->get_return_url( $order ),
		];

		wp_add_inline_script(
			'coincircuit-checkout-embed',
			'window.coincircuitPayConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);

		wp_add_inline_script(
			'coincircuit-checkout-embed',
			'(function () {' .
			'var cfg = window.coincircuitPayConfig || {};' .
			'var btn = document.getElementById("coincircuit-pay-button");' .
			'function openCheckout() {' .
			'if (!window.CoinCircuitCheckoutEmbed) { window.location = cfg.url; return; }' .
			'if (btn) btn.disabled = true;' .
			'window.CoinCircuitCheckoutEmbed.open({' .
			'url: cfg.url,' .
			'onComplete: function () { window.location = cfg.successUrl; },' .
			'onClose: function () { if (btn) btn.disabled = false; },' .
			'onLoadFailure: function () { window.location = cfg.url; }' .
			'});' .
			'}' .
			'if (btn) { btn.addEventListener("click", function (e) { e.preventDefault(); openCheckout(); }); }' .
			'openCheckout();' .
			'})();',
			'after'
		);

		echo '<p>' . esc_html__( 'Complete your payment with cryptocurrency in the secure CoinCircuit checkout.', 'coincircuit' ) . '</p>';
		echo '<button type="button" id="coincircuit-pay-button" class="button alt">' . esc_html__( 'Pay with cryptocurrency', 'coincircuit' ) . '</button>';
	}

	/**
	 * The order's live payment session: reuses the existing one when it can
	 * still be paid (so a half-paid session keeps collecting its funds and a
	 * double click does not mint duplicates), otherwise creates and records
	 * a new one.
	 *
	 * @throws Exception when a session cannot be created.
	 */
	private function ensure_session( $order ) {
		$amount = number_format( (float) $order->get_total(), 2, '.', '' );

		$existing = $this->get_reusable_session( $order, $amount );
		if ( $existing ) {
			return $existing;
		}

		return $this->create_session( $order, $amount );
	}

	/**
	 * @throws Exception when the API call fails or responds unexpectedly.
	 */
	private function create_session( $order, $amount ) {
		$api = new CoinCircuit_API( $this->get_option( 'api_key' ), $this->get_option( 'environment' ) );

		$customer = array_filter(
			[
				'email'     => $order->get_billing_email(),
				'firstName' => $order->get_billing_first_name(),
				'lastName'  => $order->get_billing_last_name(),
				'phone'     => $order->get_billing_phone(),
			],
			function ( $v ) {
				return $v !== null && $v !== '';
			}
		);

		$payload = [
			'title'       => sprintf( __( 'Order #%s', 'coincircuit' ), $order->get_order_number() ),
			'description' => sprintf( __( 'Payment for WooCommerce order #%s', 'coincircuit' ), $order->get_order_number() ),
			'amount'      => $amount,
			'currency'    => get_woocommerce_currency(),
			'customer'    => $customer,
			'metadata'    => [
				'orderId'      => (string) $order->get_id(),
				'wc_order_key' => $order->get_order_key(),
			],
			'successUrl'  => $this->get_return_url( $order ),
			'cancelUrl'   => wc_get_checkout_url(),
			'webhookUrl'  => rest_url( 'coincircuit/v1/webhook' ),
			'feePaidBy'   => $this->get_option( 'fee_paid_by', 'customer' ),
		];

		$response = $api->create_payment_session( $payload );

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			if ( is_array( $message ) ) {
				$message = implode( ' ', $message );
			}
			throw new Exception( $message ?: __( 'Unable to create CoinCircuit payment session. Please try again.', 'coincircuit' ) );
		}

		if ( empty( $response['data']['reference'] ) || empty( $response['data']['url'] ) ) {
			throw new Exception( __( 'Invalid response from CoinCircuit. Please try again.', 'coincircuit' ) );
		}

		$session = $response['data'];

		$recorded = $this->record_session( $order, $session, $amount );

		$order->update_status(
			'pending',
			sprintf( __( 'CoinCircuit: Awaiting crypto payment. Session reference: %s', 'coincircuit' ), $session['reference'] )
		);

		return $recorded;
	}

	/**
	 * The newest live session for this order and amount, partial ones
	 * first: pending or partial, matching amount, not expiring within
	 * REUSE_MIN_TTL, and (unless partially paid) no older than
	 * REUSE_PENDING_MAX_AGE.
	 */
	private function get_reusable_session( $order, $amount ) {
		$sessions = $order->get_meta( '_cc_sessions' );
		if ( ! is_array( $sessions ) ) {
			return null;
		}

		$now  = time();
		$best = null;

		foreach ( $sessions as $session ) {
			$status = $session['status'] ?? '';
			if ( ! in_array( $status, [ 'pending', 'partial' ], true ) ) {
				continue;
			}
			if ( empty( $session['url'] ) || ( $session['amount'] ?? '' ) !== $amount ) {
				continue;
			}
			$expires_at = (int) ( $session['expires_at'] ?? 0 );
			if ( $expires_at && $expires_at <= $now + self::REUSE_MIN_TTL ) {
				continue;
			}
			$created = (int) ( $session['created'] ?? 0 );
			if ( 'partial' !== $status && $created <= $now - self::REUSE_PENDING_MAX_AGE ) {
				continue;
			}

			if ( null === $best ) {
				$best = $session;
				continue;
			}
			$best_status = $best['status'] ?? '';
			if ( 'partial' === $status && 'partial' !== $best_status ) {
				$best = $session;
			} elseif ( $status === $best_status && $created > (int) ( $best['created'] ?? 0 ) ) {
				$best = $session;
			}
		}

		return $best;
	}

	/**
	 * Every session created for an order is kept, so a payment arriving on
	 * an older, superseded session still reaches the order. The
	 * per-reference meta rows let refund webhooks, which carry only the
	 * session reference, find the order with a meta query.
	 */
	private function record_session( $order, $session, $amount ) {
		$reference = (string) $session['reference'];

		$sessions = $order->get_meta( '_cc_sessions' );
		if ( ! is_array( $sessions ) ) {
			$sessions = [];
		}

		$entry = [
			'reference'  => $reference,
			'url'        => (string) $session['url'],
			'amount'     => $amount,
			'status'     => 'pending',
			'created'    => time(),
			'expires_at' => ! empty( $session['expiresAt'] ) ? (int) strtotime( $session['expiresAt'] ) : 0,
		];

		$sessions[ $reference ] = $entry;

		$order->update_meta_data( '_cc_sessions', $sessions );
		$order->update_meta_data( '_cc_session_reference', $reference );
		$order->update_meta_data( '_cc_session_url', (string) $session['url'] );
		$order->add_meta_data( '_cc_session_ref', $reference, false );
		$order->save();

		return $entry;
	}

	public function currency_admin_notice() {
		if ( ! in_array( get_woocommerce_currency(), self::SUPPORTED_CURRENCIES, true ) && $this->enabled === 'yes' ) {
			echo '<div class="notice notice-error"><p>';
			echo '<strong>' . esc_html__( 'CoinCircuit for WooCommerce', 'coincircuit' ) . ':</strong> ';
			esc_html_e( 'Your store currency is not supported by CoinCircuit. Only NGN and USD are accepted. The gateway is unavailable until the currency is changed.', 'coincircuit' );
			echo '</p></div>';
		}
	}
}
