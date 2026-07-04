<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoinCircuit_API {

	private $api_key;
	private $base_url;

	public function __construct( $api_key, $environment = 'production' ) {
		$this->api_key  = $api_key;
		$this->base_url = $environment === 'sandbox'
			? 'https://sandbox-api.coincircuit.io'
			: 'https://api.coincircuit.io';
	}

	private function request( $method, $path, $body = [] ) {
		$url  = $this->base_url . $path;
		$args = [
			'method'  => strtoupper( $method ),
			'headers' => [
				'Content-Type' => 'application/json',
				'x-api-key'    => $this->api_key,
			],
			'timeout' => 30,
		];

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );

		if ( $status_code >= 400 ) {
			$message = isset( $decoded['message'] ) ? $decoded['message'] : 'CoinCircuit API request failed.';
			if ( is_array( $message ) ) {
				$message = implode( ' ', $message );
			}
			return new WP_Error( 'coincircuit_api_error', (string) $message, [ 'status' => $status_code ] );
		}

		return $decoded;
	}

	public function create_payment_session( $payload ) {
		return $this->request( 'POST', '/api/v1/payments', $payload );
	}

	public function get_payment_session( $reference ) {
		return $this->request( 'GET', '/api/v1/payments/reference/' . rawurlencode( $reference ) );
	}
}
