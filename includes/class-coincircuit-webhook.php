<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoinCircuit_Webhook {

	const MAX_TIMESTAMP_AGE = 300;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
	}

	public function register_route() {
		register_rest_route(
			'coincircuit/v1',
			'/webhook',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function handle( WP_REST_Request $request ) {
		$raw_body   = $request->get_body();
		$timestamp  = (int) $request->get_header( 'x_coincircuit_timestamp' );
		$header_sig = $request->get_header( 'x_coincircuit_signature' );

		$gateway = $this->get_gateway();
		if ( ! $gateway ) {
			return new WP_REST_Response( [ 'error' => 'Gateway not configured.' ], 500 );
		}

		$webhook_secret = $gateway->get_option( 'webhook_secret' );

		if ( empty( $webhook_secret ) ) {
			return new WP_REST_Response( [ 'error' => 'Webhook secret not configured.' ], 500 );
		}

		if ( ! $timestamp || abs( time() - $timestamp ) > self::MAX_TIMESTAMP_AGE ) {
			return new WP_REST_Response( [ 'error' => 'Invalid or expired timestamp.' ], 400 );
		}

		$signed_payload = $timestamp . '.' . $raw_body;
		$expected       = 'v1=' . hash_hmac( 'sha256', $signed_payload, $webhook_secret );

		if ( ! hash_equals( $expected, (string) $header_sig ) ) {
			return new WP_REST_Response( [ 'error' => 'Invalid signature.' ], 401 );
		}

		$payload = json_decode( $raw_body, true );

		if ( ! is_array( $payload ) || empty( $payload['event'] ) ) {
			return new WP_REST_Response( [ 'error' => 'Invalid payload structure.' ], 400 );
		}

		$event = (string) $payload['event'];
		$data  = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : [];

		// Signed but permanently unprocessable events are acknowledged with
		// 200 below: a non-2xx would only trigger retries that can never
		// succeed.
		$reference = $this->extract_reference( $event, $data );
		if ( '' === $reference ) {
			return new WP_REST_Response( [ 'success' => true, 'ignored' => 'unrelated_event' ], 200 );
		}

		$order = $this->find_order( $data, $reference );
		if ( ! $order ) {
			return new WP_REST_Response( [ 'success' => true, 'ignored' => 'unknown_session' ], 200 );
		}

		// Exact-delivery dedupe: retries repost the identical stored body,
		// so a hash of the raw body identifies a delivery precisely. Repeat
		// events with new content (a second partial payment, another
		// transaction) hash differently and are processed.
		$dedupe_key = '_cc_wh_' . sha1( $raw_body );
		if ( $order->get_meta( $dedupe_key ) ) {
			return new WP_REST_Response( [ 'success' => true, 'ignored' => 'duplicate' ], 200 );
		}

		$this->process_event( $event, $order, $reference, $data );

		// Marked after processing: if processing fails mid-way, the retry is
		// not treated as a duplicate and gets another chance.
		$order->update_meta_data( $dedupe_key, time() );
		$order->save();

		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * The session reference the event is about. Refund events carry it under
	 * data.refund (entity distinguishes session refunds from invoice
	 * refunds); every other family carries data.session.
	 */
	private function extract_reference( $event, $data ) {
		if ( 0 === strpos( $event, 'refund.' ) ) {
			$refund = isset( $data['refund'] ) && is_array( $data['refund'] ) ? $data['refund'] : [];
			$entity = isset( $refund['entity'] ) ? (string) $refund['entity'] : '';
			if ( '' !== $entity && 'session' !== $entity ) {
				return '';
			}
			return isset( $refund['reference'] ) ? (string) $refund['reference'] : '';
		}

		return isset( $data['session']['reference'] ) ? (string) $data['session']['reference'] : '';
	}

	/**
	 * Resolve the order the session belongs to. Payment and transaction
	 * events carry our metadata, which is cross-checked against the order
	 * key; the reference must be one this order created, so a payment
	 * completing on an older, superseded session still lands on the order.
	 * Refund events carry only the reference and are matched through the
	 * per-reference meta rows.
	 */
	private function find_order( $data, $reference ) {
		$session  = isset( $data['session'] ) && is_array( $data['session'] ) ? $data['session'] : [];
		$metadata = isset( $session['metadata'] ) && is_array( $session['metadata'] ) ? $session['metadata'] : [];

		if ( ! empty( $metadata['orderId'] ) ) {
			$order = wc_get_order( (int) $metadata['orderId'] );
			if ( ! $order ) {
				return null;
			}
			$order_key = isset( $metadata['wc_order_key'] ) ? (string) $metadata['wc_order_key'] : '';
			if ( $order->get_order_key() !== $order_key ) {
				return null;
			}
			if ( ! in_array( $reference, $this->known_references( $order ), true ) ) {
				return null;
			}
			return $order;
		}

		$orders = wc_get_orders(
			[
				'limit'      => 1,
				'meta_query' => [
					[
						'key'   => '_cc_session_ref',
						'value' => $reference,
					],
				],
			]
		);

		if ( ! $orders ) {
			// Orders created by plugin 1.0.x recorded only the latest reference.
			$orders = wc_get_orders(
				[
					'limit'      => 1,
					'meta_query' => [
						[
							'key'   => '_cc_session_reference',
							'value' => $reference,
						],
					],
				]
			);
		}

		return $orders ? $orders[0] : null;
	}

	private function known_references( $order ) {
		$references = [];

		$sessions = $order->get_meta( '_cc_sessions' );
		if ( is_array( $sessions ) ) {
			$references = array_map( 'strval', array_keys( $sessions ) );
		}

		$legacy = (string) $order->get_meta( '_cc_session_reference' );
		if ( '' !== $legacy && ! in_array( $legacy, $references, true ) ) {
			$references[] = $legacy;
		}

		return $references;
	}

	private function process_event( $event, $order, $reference, $data ) {
		$session     = isset( $data['session'] ) && is_array( $data['session'] ) ? $data['session'] : [];
		$transaction = isset( $data['transaction'] ) && is_array( $data['transaction'] ) ? $data['transaction'] : ( $session['transaction'] ?? [] );
		$tx_hash     = isset( $transaction['txHash'] ) ? (string) $transaction['txHash'] : '';
		$explorer    = isset( $transaction['explorerUrl'] ) ? (string) $transaction['explorerUrl'] : '';

		// Guards computed before any mutation: a paid order is never
		// downgraded, and a cancelled or refunded order never changes status
		// from a webhook.
		$order_paid      = $order->is_paid();
		$order_cancelled = in_array( $order->get_status(), [ 'cancelled', 'refunded' ], true );

		switch ( $event ) {
			case 'payment.completed':
				$this->set_session_status( $order, $reference, 'completed' );
				if ( $order_cancelled ) {
					$order->add_order_note(
						sprintf(
							__( 'CoinCircuit: Payment completed on session %s, but this order is cancelled. Order status left unchanged - review and refund if needed.', 'coincircuit' ),
							$reference
						)
					);
				} elseif ( $order_paid ) {
					$order->add_order_note(
						sprintf(
							__( 'CoinCircuit: A second payment session (%s) completed for this order, which was already paid. Order status left unchanged - review and refund the extra payment.', 'coincircuit' ),
							$reference
						)
					);
				} else {
					$order->payment_complete( $reference );
					$note = sprintf( __( 'CoinCircuit: Payment completed. Session reference: %s', 'coincircuit' ), $reference );
					if ( $tx_hash ) {
						$note .= ' ' . sprintf( __( 'Transaction: %s', 'coincircuit' ), $tx_hash );
					}
					if ( $explorer ) {
						$note .= ' ' . sprintf( __( 'Explorer: %s', 'coincircuit' ), $explorer );
					}
					$order->add_order_note( $note );
				}
				break;

			case 'payment.partial':
				// The session is still open and the customer can pay the
				// remainder. The order keeps its needs-payment status so the
				// customer's Pay link works, and session reuse hands them
				// back to this same session to top it up.
				$this->set_session_status( $order, $reference, 'partial' );
				if ( $order_paid || $order_cancelled ) {
					$order->add_order_note(
						sprintf(
							__( 'CoinCircuit: A partial payment arrived on session %s after this order was already paid or cancelled. Order status left unchanged.', 'coincircuit' ),
							$reference
						)
					);
				} else {
					$order->add_order_note(
						__( 'CoinCircuit: A partial payment was received. The session is still open and awaiting the remaining amount.', 'coincircuit' )
					);
				}
				break;

			case 'payment.underpaid':
				// Terminal: the session closed with less than the required
				// amount. Merchant action is needed, so the order goes on
				// hold rather than failed (money was received).
				$this->set_session_status( $order, $reference, 'underpaid' );
				if ( $order_paid || $order_cancelled ) {
					$order->add_order_note(
						sprintf(
							__( 'CoinCircuit: A partial payment arrived on session %s after this order was already paid or cancelled. Order status left unchanged.', 'coincircuit' ),
							$reference
						)
					);
				} else {
					$order->update_status(
						'on-hold',
						sprintf(
							__( 'CoinCircuit: The payment session closed with less than the required amount (underpaid). Open this payment in your CoinCircuit dashboard to reopen it for the remaining amount, or refund the customer. Session reference: %s', 'coincircuit' ),
							$reference
						)
					);
				}
				break;

			case 'payment.expired':
				// Only a pending order is failed: an on-hold order holds
				// money from an earlier underpaid session, and a later empty
				// session expiring must not disturb it. Failed keeps the
				// customer's Pay link alive, and paying again creates a
				// fresh session.
				$this->set_session_status( $order, $reference, 'expired' );
				if ( ! $order_paid && ! $order_cancelled && $order->has_status( 'pending' ) ) {
					$order->update_status(
						'failed',
						__( 'CoinCircuit: The payment session expired before any payment was received.', 'coincircuit' )
					);
				}
				break;

			case 'payment.failed':
				$this->set_session_status( $order, $reference, 'failed' );
				if ( ! $order_paid && ! $order_cancelled ) {
					$note   = __( 'CoinCircuit: The payment failed.', 'coincircuit' );
					$reason = isset( $data['failureReason'] ) ? (string) $data['failureReason'] : '';
					if ( '' !== $reason ) {
						$note .= ' ' . sprintf( __( 'Reason: %s', 'coincircuit' ), $reason );
					}
					$order->update_status( 'failed', $note );
				}
				break;

			case 'transaction.received':
				$order->add_order_note(
					sprintf(
						__( 'CoinCircuit: Transaction detected on the blockchain. Hash: %s', 'coincircuit' ),
						$tx_hash
					)
				);
				break;

			case 'transaction.confirmed':
				$note = $explorer
					? sprintf( __( 'CoinCircuit: Transaction confirmed. View on explorer: %s', 'coincircuit' ), $explorer )
					: sprintf( __( 'CoinCircuit: Transaction confirmed. Hash: %s', 'coincircuit' ), $tx_hash );
				$order->add_order_note( $note );
				break;

			case 'refund.success':
				$refund   = isset( $data['refund'] ) && is_array( $data['refund'] ) ? $data['refund'] : [];
				$amount   = isset( $refund['fiatAmount'] ) && null !== $refund['fiatAmount']
					? $refund['fiatAmount']
					: ( $refund['amount'] ?? '' );
				$currency = isset( $refund['fiatCurrency'] ) && null !== $refund['fiatCurrency']
					? $refund['fiatCurrency']
					: strtoupper( (string) ( $refund['asset'] ?? '' ) );

				$note = sprintf(
					__( 'CoinCircuit: Payment refunded (%1$s %2$s). Refund ID: %3$s.', 'coincircuit' ),
					$amount,
					$currency,
					$refund['id'] ?? ''
				);
				if ( ! empty( $refund['txHash'] ) ) {
					$note .= ' ' . sprintf( __( 'Transaction: %s', 'coincircuit' ), $refund['txHash'] );
				}

				if ( $order->has_status( 'refunded' ) ) {
					$order->add_order_note( $note );
				} else {
					$order->update_status( 'refunded', $note );
				}
				break;
		}
	}

	private function set_session_status( $order, $reference, $status ) {
		$sessions = $order->get_meta( '_cc_sessions' );
		if ( ! is_array( $sessions ) || ! isset( $sessions[ $reference ] ) ) {
			return;
		}
		$sessions[ $reference ]['status'] = $status;
		$order->update_meta_data( '_cc_sessions', $sessions );
		$order->save();
	}

	private function get_gateway() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return $gateways['coincircuit'] ?? null;
	}
}
