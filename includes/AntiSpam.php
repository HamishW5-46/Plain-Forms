<?php
namespace PlainForms;

final class AntiSpam {
	public function signature( int $form_id, int $timestamp ): string {
		return hash_hmac( 'sha256', $form_id . ':' . $timestamp, wp_salt( 'auth' ) );
	}
	public function honeypot( mixed $value, bool $enabled ): bool {
		return $enabled && ( ! is_string( $value ) || '' !== $value );
	}
	public function timing( int $form_id, mixed $stamp, mixed $signature ): bool {
		if ( ! is_scalar( $stamp ) || ! ctype_digit( (string) $stamp ) || ! is_string( $signature ) ) { return false; }
		$age = time() - (int) $stamp;
		return $age >= 2 && $age <= DAY_IN_SECONDS && hash_equals( $this->signature( $form_id, (int) $stamp ), $signature );
	}
	/** Only REMOTE_ADDR is trusted. Proxy forwarding headers are deliberately not trusted. */
	public function source_hash( string $ip ): ?string {
		$packed = @inet_pton( $ip );
		return false === $packed ? null : hash_hmac( 'sha256', $packed, wp_salt( 'auth' ) );
	}
}
