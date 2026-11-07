<?php
namespace PlainForms\Rest;
use PlainForms\{Capabilities, Settings, Turnstile};

/** Read-only, authenticated check of the administrator's current (possibly unsaved) keys. */
final class TurnstileController {
	public function register(): void {
		register_rest_route( 'plain-forms/v1', '/admin/turnstile-test', array(
			'methods' => 'POST',
			'permission_callback' => static fn (): bool => current_user_can( Capabilities::SETTINGS ),
			'callback' => array( $this, 'test' ),
		) );
	}
	public function test( \WP_REST_Request $request ): \WP_REST_Response {
		$body = (string) $request->get_body();
		if ( strlen( $body ) > 10000 ) { return $this->reply( false, 'invalid_request', 'The verification request is too large.', 413 ); }
		$data = json_decode( $body, true );
		$allowed = array( 'turnstile_site_key', 'turnstile_secret_key', 'turnstile_hostname', 'turnstile_action', 'clear_turnstile_secret' );
		if ( ! is_array( $data ) || ! is_array( $data['settings'] ?? null ) || array_diff( array_keys( $data['settings'] ), $allowed ) || ! is_string( $data['token'] ?? null ) || '' === $data['token'] || strlen( $data['token'] ) > 2048 ) {
			return $this->reply( false, 'invalid_request', 'Complete the test challenge using valid Turnstile settings.', 400 );
		}
		$settings = ( new Settings() )->validate_update( $data['settings'] );
		if ( is_wp_error( $settings ) ) { return $this->reply( false, 'invalid_settings', 'Check the key, hostname and action formats before testing.', 400 ); }
		$verifier = new Turnstile();
		if ( $verifier->verify( $data['token'], $settings ) ) {
			return $this->reply( true, 'verified', 'Turnstile verified successfully with these settings. Save settings to apply any changes.', 200 );
		}
		$messages = array(
			'turnstile_not_configured' => 'Enter both keys, or keep the saved secret, before testing.',
			'turnstile_secret_invalid' => 'Cloudflare rejected the secret key. Copy the secret belonging to this site key.',
			'turnstile_token_rejected' => 'Cloudflare rejected this token. Check that both keys belong to the same widget, then test again.',
			'turnstile_invalid_token' => 'The challenge did not provide a valid token. Run the test again.',
			'turnstile_token_expired' => 'The test token expired or was already used. Run the test again.',
			'turnstile_hostname_mismatch' => 'The expected hostname does not match this test challenge. Check the www prefix and the hostname shown in this panel.',
			'turnstile_action_mismatch' => 'The expected action does not match the test challenge. Check the action and run the test again.',
			'turnstile_timestamp_invalid' => 'The challenge timestamp was invalid or expired. Check the server clock and run the test again.',
		);
		return $this->reply( false, $verifier->failure_code(), $messages[ $verifier->failure_code() ] ?? 'The server could not verify the challenge with Cloudflare. Check outbound HTTPS connectivity and try again.', 400 );
	}
	private function reply( bool $success, string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response( compact( 'success', 'code', 'message' ), $status, array( 'Cache-Control' => 'no-store, private' ) );
	}
}
