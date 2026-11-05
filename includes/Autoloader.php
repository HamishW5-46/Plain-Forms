<?php
namespace PlainForms;

final class Autoloader {
	public static function register( string $directory ): void {
		spl_autoload_register( static function ( string $class ) use ( $directory ): void {
			$prefix = 'PlainForms\\';
			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_]*(?:\\\\[A-Za-z][A-Za-z0-9_]*)*$/D', $relative ) ) {
				return;
			}
			$path = str_replace( '\\', '/', $relative ) . '.php';
			// Domain classes live in includes; feature namespaces use their own directories.
			$file = $directory . '/' . ( str_contains( $relative, '\\' ) ? '' : 'includes/' ) . $path;
			if ( is_file( $file ) ) {
				require_once $file;
			}
		} );
	}
}
