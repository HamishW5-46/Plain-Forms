<?php
namespace PlainForms;

final class Turnstile {
	public function verify( string $token, array $settings ): bool {
		if ( '' === $token || strlen( $token ) > 2048 || '' === $settings['turnstile_secret_key'] || '' === $settings['turnstile_site_key'] ) { return false; }
		$response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
			'timeout' => 10, 'redirection' => 0, 'limit_response_size' => 16000,
			'body' => array( 'secret' => $settings['turnstile_secret_key'], 'response' => $token ),
		) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return false; }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) && $this->valid_response( $data, $settings );
	}
	public function valid_response( array $data, array $settings ): bool {
		if ( true !== ( $data['success'] ?? false ) ) { return false; }
		if ( $settings['turnstile_hostname'] && ( ! is_string( $data['hostname'] ?? null ) || strtolower( $settings['turnstile_hostname'] ) !== strtolower( $data['hostname'] ) ) ) { return false; }
		if ( $settings['turnstile_action'] && $settings['turnstile_action'] !== ( $data['action'] ?? '' ) ) { return false; }
		if ( isset( $data['challenge_ts'] ) ) {
			$stamp = is_string( $data['challenge_ts'] ) ? strtotime( $data['challenge_ts'] ) : false;
			if ( false === $stamp || time() - $stamp > 300 || $stamp > time() + 30 ) { return false; }
		}
		return true;
	}
}
