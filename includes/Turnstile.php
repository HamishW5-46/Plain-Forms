<?php
namespace PlainForms;

final class Turnstile {
	/** Fixed diagnostic codes only. Never retain provider bodies, keys, tokens or hostnames. */
	public const FAILURE_EVENTS = array(
		'turnstile_invalid_token', 'turnstile_not_configured', 'turnstile_network_failed',
		'turnstile_http_failed', 'turnstile_invalid_response', 'turnstile_secret_invalid',
		'turnstile_token_expired', 'turnstile_token_rejected', 'turnstile_provider_failed',
		'turnstile_hostname_mismatch', 'turnstile_action_mismatch', 'turnstile_timestamp_invalid',
	);
	private string $failure = '';

	public function failure_code(): string { return $this->failure; }
	private function reject( string $code ): bool { $this->failure = $code; return false; }

	public function verify( string $token, array $settings ): bool {
		$this->failure = '';
		if ( '' === $token || strlen( $token ) > 2048 ) { return $this->reject( 'turnstile_invalid_token' ); }
		if ( '' === $settings['turnstile_secret_key'] || '' === $settings['turnstile_site_key'] ) { return $this->reject( 'turnstile_not_configured' ); }
		$response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
			'timeout' => 10, 'redirection' => 0, 'limit_response_size' => 16000,
			'body' => array( 'secret' => $settings['turnstile_secret_key'], 'response' => $token ),
		) );
		if ( is_wp_error( $response ) ) { return $this->reject( 'turnstile_network_failed' ); }
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) { return $this->reject( 'turnstile_http_failed' ); }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $this->valid_response( $data, $settings ) : $this->reject( 'turnstile_invalid_response' );
	}
	public function valid_response( array $data, array $settings ): bool {
		$this->failure = '';
		if ( true !== ( $data['success'] ?? false ) ) {
			$codes = is_array( $data['error-codes'] ?? null ) ? $data['error-codes'] : array();
			if ( array_intersect( array( 'missing-input-secret', 'invalid-input-secret' ), array_filter( $codes, 'is_string' ) ) ) { return $this->reject( 'turnstile_secret_invalid' ); }
			if ( in_array( 'timeout-or-duplicate', $codes, true ) ) { return $this->reject( 'turnstile_token_expired' ); }
			if ( in_array( 'internal-error', $codes, true ) ) { return $this->reject( 'turnstile_provider_failed' ); }
			return $this->reject( 'turnstile_token_rejected' );
		}
		if ( $settings['turnstile_hostname'] && ( ! is_string( $data['hostname'] ?? null ) || strtolower( $settings['turnstile_hostname'] ) !== strtolower( $data['hostname'] ) ) ) { return $this->reject( 'turnstile_hostname_mismatch' ); }
		if ( $settings['turnstile_action'] && $settings['turnstile_action'] !== ( $data['action'] ?? '' ) ) { return $this->reject( 'turnstile_action_mismatch' ); }
		if ( isset( $data['challenge_ts'] ) ) {
			$stamp = is_string( $data['challenge_ts'] ) ? strtotime( $data['challenge_ts'] ) : false;
			if ( false === $stamp || time() - $stamp > 300 || $stamp > time() + 30 ) { return $this->reject( 'turnstile_timestamp_invalid' ); }
		}
		return true;
	}
}
