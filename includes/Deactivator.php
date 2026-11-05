<?php
namespace PlainForms;

final class Deactivator {
	public const CLEANUP_HOOK = 'plainforms_cleanup';

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		wp_clear_scheduled_hook( 'plainforms_cleanup_batch' );
	}
}
