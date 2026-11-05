<?php
namespace PlainForms;

final class Activator {
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate Plain Forms separately on each site. Network activation is not supported.', 'plain-forms' ) );
		}
		if ( ! self::install() ) {
			wp_die( esc_html__( 'Plain Forms could not install its database table. Please ask your administrator to check database permissions.', 'plain-forms' ) );
		}
	}

	public static function install(): bool {
		if ( Database::VERSION !== get_option( Database::VERSION_OPTION ) && ! Database::install() ) {
			return false;
		}
		( new Settings() )->install();
		Capabilities::install();
		( new Privacy() )->schedule();
		update_option( Plugin::VERSION_OPTION, Plugin::VERSION, false );
		return true;
	}
}
