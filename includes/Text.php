<?php
namespace PlainForms;

final class Text {
	public static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : ( preg_match_all( '/./us', $value ) ?: strlen( $value ) );
	}
	public static function cut( string $value, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) { return mb_substr( $value, 0, $length, 'UTF-8' ); }
		return preg_match( '/^.{0,' . $length . '}/us', $value, $m ) ? $m[0] : substr( $value, 0, $length );
	}
}
