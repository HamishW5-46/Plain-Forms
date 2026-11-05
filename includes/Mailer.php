<?php
namespace PlainForms;

final class Mailer {
	public function send( array $form, array $values, int $submission_id, string $timestamp ): bool {
		return $this->message( $form, $values, $submission_id, $timestamp, 'notification' );
	}
	public function receipt( array $form, array $values, int $submission_id, string $timestamp, ?string $original_field = null ): bool {
		return $this->message( $form, $values, $submission_id, $timestamp, 'receipt', $original_field );
	}
	private function message( array $form, array $values, int $submission_id, string $timestamp, string $kind, ?string $original_field = null ): bool {
		$config = ( new FormSchema() )->validate( $form['config'] );
		if ( is_wp_error( $config ) ) { return false; }
		$form['config'] = $config; $n = $config[ $kind ]; $s = ( new Settings() )->get();
		if ( ! $n['enabled'] ) { return false; }
		$types = array_column( $config['fields'], 'type', 'id' ); $reply = '';
		if ( 'receipt' === $kind ) {
			$field = $original_field ?? $n['email_field'];
			$allowed_types = null === $original_field ? $types : ( $values['_plainforms']['types'] ?? array() );
			if ( 'email' !== ( $allowed_types[ $field ] ?? '' ) ) { return false; }
			$recipients = array( $values[ $field ] ?? '' ); $reply = $n['reply_to'];
		} else {
			$recipients = $n['recipients'] ?: $s['recipients'];
			$field = $n['reply_to_field'];
			if ( $field && 'email' === ( $types[ $field ] ?? '' ) && ! empty( $values[ $field ] ) ) { $reply = $values[ $field ]; }
		}
		if ( ! is_array( $recipients ) || ! $recipients ) { return false; }
		foreach ( $recipients as $email ) { if ( ! $this->email( $email ) ) { return false; } }
		if ( ! is_string( $reply ) || ( '' !== $reply && ! $this->email( $reply ) ) ) { return false; }
		$headers = array( 'Content-Type: text/' . ( 'html' === $n['format'] ? 'html' : 'plain' ) . '; charset=UTF-8' );
		if ( preg_match( '/[\r\n]/', $s['sender_name'] ) ) { return false; }
		if ( $s['sender_email'] ) {
			if ( ! $this->email( $s['sender_email'] ) ) { return false; }
			$name = str_replace( array( '<', '>', '"' ), '', sanitize_text_field( $s['sender_name'] ) );
			$headers[] = 'From: ' . ( $name ? $name . ' <' . $s['sender_email'] . '>' : $s['sender_email'] );
		}
		if ( '' !== $reply ) { $headers[] = 'Reply-To: ' . $reply; }
		$tokens = array( 'form_title' => $values['_plainforms']['title'] ?? $form['title'], 'submission_id' => $submission_id ?: 'not stored', 'system.site_name' => get_bloginfo( 'name' ), 'system.submitted_at' => $timestamp );
		foreach ( $config['fields'] as $f ) { $tokens[ $f['id'] ] = self::value( $values[ $f['id'] ] ?? '' ); }
		$template = 'notification' === $kind ? ( $n['subject'] ?: $s['subject'] ) : $n['subject'];
		if ( preg_match( '/[\r\n]/', $template ) ) { return false; }
		$subject = preg_replace_callback( '/\{([^{}]+)\}/', static fn ( array $m ): string => sanitize_text_field( (string) ( $tokens[ $m[1] ] ?? '' ) ), $template );
		if ( 'html' === $n['format'] ) {
			$result = ( new EmailTemplates() )->render( $form, $values, $submission_id, $timestamp, $kind );
			if ( is_wp_error( $result ) ) { return false; }
			$body = $result['html']; $alternate = EmailTemplates::plaintext( $body );
		} else { $body = $this->plaintext( $form, $values, $submission_id, $timestamp, $kind ); $alternate = ''; }
		return $this->deliver( $recipients, Text::cut( sanitize_text_field( $subject ), 500 ), $body, $headers, $alternate, $s );
	}
	private function email( mixed $value ): bool {
		return is_string( $value ) && ! preg_match( '/[\r\n]/', $value ) && (bool) is_email( $value );
	}
	private function plaintext( array $form, array $values, int $id, string $timestamp, string $kind ): string {
		$title = $values['_plainforms']['title'] ?? $form['title'];
		if ( 'receipt' === $kind ) { return "Thanks for getting in touch.\n\nWe have received your enquiry through " . $title . ".\nOur team will be in touch if a response is needed.\n\n" . get_bloginfo( 'name' ); }
		$lines = array( 'Form: ' . $title, 'Submission: ' . ( $id ?: 'not stored' ), 'Submitted (UTC): ' . $timestamp, '' );
		$labels = $values['_plainforms']['labels'] ?? array_column( $form['config']['fields'], 'label', 'id' );
		foreach ( $labels as $key => $label ) { $lines[] = $label . ': ' . self::value( $values[ $key ] ?? '' ); }
		if ( $id ) { $lines[] = ''; $lines[] = admin_url( 'admin.php?page=plain-forms-view&id=' . $id ); }
		return implode( "\n", $lines );
	}
	private function deliver( array $recipients, string $subject, string $body, array $headers, string $alternate, array $settings ): bool {
		$name_filter = static fn (): string => sanitize_text_field( $settings['sender_name'] );
		$filter_name = '' !== $settings['sender_name'] && '' === $settings['sender_email'];
		$mailer = null; $old_alternate = '';
		$alt_filter = static function ( $instance ) use ( &$mailer, &$old_alternate, $alternate ): void {
			$mailer = $instance; $old_alternate = $instance->AltBody; $instance->AltBody = $alternate;
		};
		if ( $filter_name ) { add_filter( 'wp_mail_from_name', $name_filter ); }
		if ( '' !== $alternate ) { add_action( 'phpmailer_init', $alt_filter ); }
		try { return wp_mail( $recipients, $subject, $body, $headers ); }
		catch ( \Throwable $e ) { return false; }
		finally {
			if ( $filter_name ) { remove_filter( 'wp_mail_from_name', $name_filter ); }
			if ( '' !== $alternate ) { remove_action( 'phpmailer_init', $alt_filter ); }
			if ( null !== $mailer ) { $mailer->AltBody = $old_alternate; }
		}
	}
	public static function value( mixed $v ): string {
		if ( is_bool( $v ) ) { return $v ? 'Yes' : 'No'; }
		if ( is_array( $v ) ) { return implode( ', ', array_map( static fn ( mixed $item ): string => is_scalar( $item ) ? (string) $item : '', $v ) ); }
		return is_scalar( $v ) ? (string) $v : '';
	}
}
