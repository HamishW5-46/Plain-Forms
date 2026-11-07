<?php
namespace PlainForms;

final class Logger {
	/** Call sites supply fixed event codes only, never exceptions, tokens or visitor values. */
	public function event( string $event ): void {
		if ( ( new Settings() )->get()['debug_logging'] && in_array( $event, array_merge( array( 'storage_failed', 'mail_failed', 'receipt_mail_failed', 'turnstile_failed', 'cleanup_failed' ), Turnstile::FAILURE_EVENTS ), true ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Explicitly opted-in diagnostic event codes only; no tokens, values or exception text.
			error_log( '[Plain Forms] ' . $event );
		}
	}
}
