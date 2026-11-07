<?php
namespace PlainForms;

final class SubmissionService {
	public function process( int $id, array $request, array $server ): array {
		$form = ( new FormRepository() )->find( $id );
		if ( is_wp_error( $form ) || ! $form['config']['enabled'] || ! $form['config']['fields'] ) { return $this->error( 'unavailable', 'This form is unavailable.', 404 ); }
		$c = $form['config']; $s = ( new Settings() )->get();
		if ( $c['receipt']['enabled'] && ( $c['storage']['enabled'] ?? $s['storage_enabled'] ) && ! Database::receipts_ready() ) { return $this->error( 'temporarily_unavailable', 'This form is temporarily unavailable. Please try again later.', 503 ); }
		if ( ! ( $c['storage']['enabled'] ?? $s['storage_enabled'] ) && ! $c['notification']['enabled'] ) { return $this->error( 'unavailable', 'This form is unavailable.', 503 ); }
		$request['fields'] = $request['fields'] ?? array();
		if ( ! is_array( $request['fields'] ) || count( $request['fields'] ) > 100 ) { return $this->error( 'invalid_request', 'Please check your form and try again.', 400 ); }
		$success = array( 'success' => true, 'message' => $c['confirmation']['message'] ?: $s['success_message'] );
		$spam = new AntiSpam();
		if ( $spam->honeypot( $request['website'] ?? '', $s['honeypot_enabled'] ) ) { return array( $success, 200 ); }
		if ( $s['timing_enabled'] && ! $spam->timing( $id, $request['started'] ?? '', $request['signature'] ?? '' ) ) { return $this->error( 'try_again', 'Please wait a moment, then try again. Reload this page if the problem continues.', 400 ); }
		$ip = $spam->source_hash( (string) ( $server['REMOTE_ADDR'] ?? '' ) );
		if ( $s['hash_ip'] && ( ! $ip || ! ( new RateLimiter() )->allow( $ip, $id, (int) $s['rate_limit_count'], (int) $s['rate_limit_window'] ) ) ) { return $this->error( 'rate_limited', 'Please wait a few minutes before trying again.', 429 ); }
		if ( $c['turnstile'] ?? $s['turnstile_enabled'] ) {
			$token = $request['cf-turnstile-response'] ?? '';
			$turnstile = new Turnstile();
			if ( ! is_string( $token ) || ! $turnstile->verify( $token, $s ) ) {
				( new Logger() )->event( is_string( $token ) ? $turnstile->failure_code() : 'turnstile_invalid_token' );
				return $this->error( 'verification_failed', 'Please complete the verification and try again.', 400 );
			}
		}
		$validation = ( new ValidationService() )->validate( $c['fields'], $request['fields'] );
		if ( $validation['errors'] ) { return array( array( 'success' => false, 'code' => 'validation_failed', 'message' => 'Please check the highlighted fields.', 'fields' => $validation['errors'] ), 422 ); }
		$values = $validation['data'];
		$receipt_field = $c['receipt']['email_field'];
		$receipt_status = $c['receipt']['enabled'] ? ( '' !== ( $values[ $receipt_field ] ?? '' ) ? 'pending' : 'skipped' ) : 'disabled';
		$stored = 0; $timestamp = gmdate( 'Y-m-d H:i:s' );
		$repo = new SubmissionRepository();
		if ( $c['storage']['enabled'] ?? $s['storage_enabled'] ) {
			$data = $values;
			$data['_plainforms'] = array( 'title' => $form['title'], 'labels' => array_column( $c['fields'], 'label', 'id' ), 'types' => array_column( $c['fields'], 'type', 'id' ), 'receipt_email_field' => $c['receipt']['enabled'] ? $receipt_field : '' );
			$meta = array( 'receipt_status' => $receipt_status, 'ip_hash' => $s['hash_ip'] ? $ip : null );
			if ( $s['store_user_agent'] ) { $meta['user_agent'] = Text::cut( sanitize_text_field( (string) ( $server['HTTP_USER_AGENT'] ?? '' ) ), 500 ); }
			if ( $s['store_referer'] ) {
				// Omit query/fragment to avoid retaining URL tokens or other unnecessary personal data.
				$ref = wp_parse_url( (string) ( $server['HTTP_REFERER'] ?? '' ) );
				$meta['referer'] = is_array( $ref ) && isset( $ref['host'] ) ? esc_url_raw( ( $ref['scheme'] ?? 'https' ) . '://' . $ref['host'] . ( $ref['path'] ?? '/' ) ) : '';
			}
			$stored = $repo->create( $id, $data, $meta );
			if ( is_wp_error( $stored ) ) { ( new Logger() )->event( 'storage_failed' ); return $this->error( 'temporarily_unavailable', 'Your enquiry could not be saved. Please try again later.', 503 ); }
		}
		if ( $c['notification']['enabled'] ) {
			$sent = ( new Mailer() )->send( $form, $values, $stored, $timestamp );
			if ( $stored && ! $repo->delivery( $stored, $sent ) ) { ( new Logger() )->event( 'storage_failed' ); }
			if ( ! $sent ) {
				( new Logger() )->event( 'mail_failed' );
				if ( ! $stored ) { return $this->error( 'temporarily_unavailable', 'Your enquiry could not be sent. Please try again later.', 503 ); }
			}
		}
		if ( 'pending' === $receipt_status ) {
			$sent = ( new Mailer() )->receipt( $form, $values, $stored, $timestamp );
			if ( $stored && ! $repo->receipt_delivery( $stored, $sent ) ) { ( new Logger() )->event( 'storage_failed' ); }
			if ( ! $sent ) { ( new Logger() )->event( 'receipt_mail_failed' ); }
		}
		if ( $stored ) { $success['submissionId'] = $stored; }
		return array( $success, 200 );
	}
	private function error( string $code, string $message, int $status ): array {
		return array( array( 'success' => false, 'code' => $code, 'message' => $message ), $status );
	}
	public function resend( int $id ): bool {
		if ( ! current_user_can( Capabilities::VIEW ) || ! current_user_can( Capabilities::MANAGE ) ) { return false; }
		$repo = new SubmissionRepository(); $row = $repo->find( $id );
		if ( ! $row || ! $row['data_valid'] ) { return false; }
		$form = ( new FormRepository() )->find( (int) $row['form_id'] );
		if ( is_wp_error( $form ) || ! $form['config']['notification']['enabled'] ) { return false; }
		$ok = ( new Mailer() )->send( $form, $row['data'], $id, $row['created_at'] );
		$repo->delivery( $id, $ok );
		return $ok;
	}
	/** Explicit separate action: resending the administrator notification never resends a receipt. */
	public function resend_receipt( int $id ): bool {
		if ( ! current_user_can( Capabilities::VIEW ) || ! current_user_can( Capabilities::MANAGE ) ) { return false; }
		$repo = new SubmissionRepository(); $row = $repo->find( $id );
		if ( ! $row || ! $row['data_valid'] || ! in_array( $row['receipt_status'], array( 'pending', 'sent', 'failed' ), true ) ) { return false; }
		$field = $row['data']['_plainforms']['receipt_email_field'] ?? '';
		if ( '' === $field ) { return false; }
		$form = ( new FormRepository() )->find( (int) $row['form_id'] );
		if ( is_wp_error( $form ) || ! $form['config']['receipt']['enabled'] ) { return false; }
		$sent = ( new Mailer() )->receipt( $form, $row['data'], $id, $row['created_at'], $field );
		$repo->receipt_delivery( $id, $sent );
		return $sent;
	}

}
