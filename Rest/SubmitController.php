<?php
namespace PlainForms\Rest;
use PlainForms\{AntiSpam, FormRepository, SubmissionService};

final class SubmitController {
	public function register(): void {
		register_rest_route( 'plain-forms/v1', '/forms/(?P<id>\d+)/submit', array(
			'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( $this, 'submit' ),
			'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
		) );
		register_rest_route( 'plain-forms/v1', '/forms/(?P<id>\d+)/start', array(
			'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( $this, 'start' ),
			'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
		) );
	}
	public function start( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb; $previous = $wpdb->suppress_errors( true );
		try { $id = (int) $request['id']; $form = ( new FormRepository() )->find( $id ); }
		finally { $wpdb->suppress_errors( $previous ); }
		if ( is_wp_error( $form ) || ! $form['config']['enabled'] ) { return new \WP_REST_Response( array( 'success' => false, 'message' => 'Form unavailable.' ), 404 ); }
		$time = time();
		return new \WP_REST_Response( array( 'started' => $time, 'signature' => ( new AntiSpam() )->signature( $id, $time ) ), 200, array( 'Cache-Control' => 'no-store, private' ) );
	}
	public function submit( \WP_REST_Request $request ): \WP_REST_Response {
		// Constructed REST requests may have a null body; normalise it before size/JSON checks.
		$body = (string) $request->get_body();
		if ( strlen( $body ) > 150000 || (int) $request->get_header( 'content-length' ) > 150000 ) { return new \WP_REST_Response( array( 'success' => false, 'code' => 'invalid_request', 'message' => 'This form submission is too large.' ), 413 ); }
		global $wpdb; $previous = $wpdb->suppress_errors( true );
		$data = $request->is_json_content_type() ? json_decode( $body, true ) : $request->get_body_params();
		try {
			$result = is_array( $data ) ? ( new SubmissionService() )->process( (int) $request['id'], $data, $_SERVER ) : array( array( 'success' => false, 'code' => 'invalid_request', 'message' => 'Invalid form submission.' ), 400 );
		} catch ( \Throwable $e ) {
			$result = array( array( 'success' => false, 'code' => 'temporarily_unavailable', 'message' => 'Your enquiry could not be processed. Please try again later.' ), 503 );
		}
		finally { $wpdb->suppress_errors( $previous ); }
		return new \WP_REST_Response( $result[0], $result[1], array( 'Cache-Control' => 'no-store, private' ) );
	}
	public function fallback(): void {
		$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) && is_string( $_SERVER['CONTENT_LENGTH'] ) ? absint( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : 0;
		if ( $content_length > 150000 ) { wp_die( 'This form submission is too large.', '', array( 'response' => 413 ) ); }
		global $wpdb; $previous = $wpdb->suppress_errors( true );
		// Public anonymous submissions use the shared schema/anti-spam pipeline, not session nonces.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Intentional anonymous endpoint; no authenticated state changes.
		$request = wp_unslash( $_POST );
		try { $result = ( new SubmissionService() )->process( absint( $request['form_id'] ?? 0 ), $request, $_SERVER ); }
		catch ( \Throwable $e ) { $result = array( array( 'success' => false, 'message' => 'Your enquiry could not be processed. Please try again later.' ), 503 ); }
		finally { $wpdb->suppress_errors( $previous ); }
		$text = esc_html( $result[0]['message'] );
		if ( ! empty( $result[0]['fields'] ) ) { $text .= '<ul>'; foreach ( $result[0]['fields'] as $field => $message ) { $text .= '<li>' . esc_html( $field . ': ' . $message ) . '</li>'; } $text .= '</ul>'; }
		if ( ! $result[0]['success'] ) { $text .= '<p>Use your browser’s Back button to return to the form and correct your answers.</p>'; }
		wp_die( wp_kses_post( $text ), esc_html__( 'Enquiry', 'plain-forms' ), array( 'response' => absint( $result[1] ), 'back_link' => (bool) ! $result[0]['success'] ) );
	}
}
