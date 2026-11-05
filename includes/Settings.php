<?php
namespace PlainForms;

final class Settings {
	public const OPTION = 'plainforms_settings';
	public const GROUP = 'plainforms_settings';

	public function defaults(): array {
		return array(
			'storage_enabled' => true, 'mark_read_on_view' => true,
			'success_message' => __( 'Thanks, your message has been sent.', 'plain-forms' ),
			'recipients' => array( get_option( 'admin_email', '' ) ),
			'sender_name' => '', 'sender_email' => '',
			'subject' => 'Website enquiry: {form_title}',
			'turnstile_enabled' => false, 'turnstile_site_key' => '', 'turnstile_secret_key' => '',
			'turnstile_hostname' => '', 'turnstile_action' => '',
			'rate_limit_count' => 5, 'rate_limit_window' => 600,
			'honeypot_enabled' => true, 'timing_enabled' => false,
			'retention_days' => 365, 'auto_delete_expired' => true,
			'store_user_agent' => false, 'store_referer' => false, 'hash_ip' => true,
			'delete_on_uninstall' => false, 'debug_logging' => false,
		);
	}

	public function install(): void {
		add_option( self::OPTION, $this->defaults(), '', false );
	}

	/** Internal use only: includes the secret; never localise this array into JavaScript. */
	public function get(): array {
		$stored = get_option( self::OPTION, array() );
		$defaults = $this->defaults();
		$result = $defaults;
		foreach ( $defaults as $key => $default ) {
			$value = is_array( $stored ) ? ( $stored[ $key ] ?? $default ) : $default;
			if ( is_bool( $default ) ) { $result[ $key ] = in_array( $value, array( true, 1, '1' ), true ); }
			elseif ( is_int( $default ) ) { $result[ $key ] = is_int( $value ) ? $value : $default; }
			elseif ( is_string( $default ) ) { $result[ $key ] = is_string( $value ) ? $value : $default; }
			elseif ( is_array( $value ) && count( array_filter( $value, 'is_string' ) ) === count( $value ) ) { $result[ $key ] = array_values( $value ); }
		}
		$result['rate_limit_count'] = max( 1, min( 1000, $result['rate_limit_count'] ) );
		$result['rate_limit_window'] = max( 60, min( 86400, $result['rate_limit_window'] ) );
		$result['retention_days'] = max( 0, min( 36500, $result['retention_days'] ) );
		return $result;
	}

	public function register(): void {
		register_setting( self::GROUP, self::OPTION, array(
			'type' => 'object', 'show_in_rest' => false,
			'sanitize_callback' => array( $this, 'sanitize' ),
		) );
		add_filter( 'option_page_capability_' . self::GROUP, static fn (): string => Capabilities::SETTINGS );
	}

	/** REST writes must report invalid input rather than silently retaining/clamping a value. */
	public function validate_update( mixed $input ): array|\WP_Error {
		if ( ! is_array( $input ) ) { return new \WP_Error( 'invalid_settings', 'Enter valid settings.', array( 'status' => 400 ) ); }
		$defaults = $this->defaults(); $errors = array();
		foreach ( $input as $key => $value ) {
			if ( 'clear_turnstile_secret' === $key ) {
				if ( ! is_bool( $value ) ) { $errors[ $key ] = 'Choose a valid setting.'; }
				continue;
			}
			if ( ! array_key_exists( $key, $defaults ) ) { $errors[ $key ] = 'Unknown setting.'; continue; }
			$default = $defaults[ $key ];
			if ( is_bool( $default ) ) {
				if ( ! is_bool( $value ) ) { $errors[ $key ] = 'Choose a valid setting.'; }
			}
			elseif ( is_int( $default ) ) {
				$bounds = array( 'rate_limit_count' => array( 1, 1000 ), 'rate_limit_window' => array( 60, 86400 ), 'retention_days' => array( 0, 36500 ) );
				if ( ! is_int( $value ) || $value < $bounds[ $key ][0] || $value > $bounds[ $key ][1] ) { $errors[ $key ] = 'Enter a number from ' . $bounds[ $key ][0] . ' to ' . $bounds[ $key ][1] . '.'; }
			} elseif ( 'recipients' === $key ) {
				if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 20 ) { $errors[ $key ] = 'Enter up to 20 email addresses.'; continue; }
				foreach ( $value as $email ) { if ( ! is_string( $email ) || ! is_email( $email ) || preg_match( '/[\r\n]/', $email ) ) { $errors[ $key ] = 'Enter valid email addresses.'; break; } }
			} elseif ( ! is_string( $value ) || strlen( $value ) > 20000 ) { $errors[ $key ] = 'Enter a valid text value.'; }
			elseif ( in_array( $key, array( 'sender_name', 'sender_email', 'subject' ), true ) && preg_match( '/[\r\n]/', $value ) ) { $errors[ $key ] = 'This value must be on one line.'; }
			elseif ( 'sender_email' === $key && '' !== $value && ! is_email( $value ) ) { $errors[ $key ] = 'Enter a valid sender email address.'; }
			elseif ( 'turnstile_hostname' === $key && '' !== $value && ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/iD', $value ) ) { $errors[ $key ] = 'Enter a hostname without a protocol or path.'; }
			elseif ( 'turnstile_action' === $key && '' !== $value && ! preg_match( '/^[a-zA-Z0-9_-]{1,32}$/D', $value ) ) { $errors[ $key ] = 'Use up to 32 letters, numbers, underscores or hyphens.'; }
		}
		if ( $errors ) { return new \WP_Error( 'invalid_settings', 'Please check the highlighted settings.', array( 'status' => 400, 'fields' => $errors ) ); }
		return $this->sanitize( $input );
	}

	/** Persist one canonical validated value, even when the Settings API sanitizes it again. */
	public function update( mixed $input ): array|\WP_Error {
		if ( ! current_user_can( Capabilities::SETTINGS ) ) { return new \WP_Error( 'forbidden', 'You cannot change form settings.', array( 'status' => 403 ) ); }
		$data = $this->validate_update( $input );
		if ( is_wp_error( $data ) ) { return $data; }
		if ( get_option( self::OPTION ) === $data ) { return $data; }
		// Blank-key preservation is an input rule, not a rule for an already validated value.
		// Reapplying it here would undo an explicit key removal. Scope the canonicalizer to this write.
		$canonical = static fn (): array => $data;
		add_filter( 'sanitize_option_' . self::OPTION, $canonical, PHP_INT_MAX );
		try { $saved = update_option( self::OPTION, $data, false ); }
		finally { remove_filter( 'sanitize_option_' . self::OPTION, $canonical, PHP_INT_MAX ); }
		return $saved ? $data : new \WP_Error( 'save_failed', 'Settings could not be saved. Please try again.', array( 'status' => 500 ) );
	}

	/** Partial updates preserve omitted values. Blank secret preserves it; explicit clear removes it. */
	public function sanitize( mixed $input ): array {
		$current = $this->get();
		if ( ! current_user_can( Capabilities::SETTINGS ) || ! is_array( $input ) ) {
			return $current;
		}
		$result = $current;
		foreach ( $this->defaults() as $key => $default ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = $input[ $key ];
			if ( is_bool( $default ) ) {
				$result[ $key ] = in_array( $value, array( true, 1, '1' ), true );
			} elseif ( in_array( $key, array( 'rate_limit_count', 'rate_limit_window', 'retention_days' ), true ) ) {
				$limits = array( 'rate_limit_count' => array( 1, 1000 ), 'rate_limit_window' => array( 60, 86400 ), 'retention_days' => array( 0, 36500 ) );
				if ( is_scalar( $value ) && false !== filter_var( $value, FILTER_VALIDATE_INT ) ) {
					$result[ $key ] = max( $limits[ $key ][0], min( $limits[ $key ][1], (int) $value ) );
				}
			} elseif ( 'recipients' === $key ) {
				$emails = is_string( $value ) ? preg_split( '/[,;\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY ) : $value;
				if ( is_array( $emails ) && count( $emails ) <= 20 ) {
					$valid = array();
					foreach ( $emails as $email ) {
						if ( ! is_string( $email ) || preg_match( '/[\r\n]/', $email ) || ! is_email( trim( $email ) ) ) {
							add_settings_error( self::OPTION, 'invalid_email', __( 'Enter valid recipient email addresses.', 'plain-forms' ) );
							continue 2;
						}
						$valid[] = sanitize_email( trim( $email ) );
					}
					$result[ $key ] = array_values( array_unique( $valid ) );
				}
			} elseif ( is_string( $value ) ) {
				if ( 'turnstile_secret_key' === $key && '' === trim( $value ) ) {
					continue;
				}
				if ( 'sender_email' === $key && '' !== $value && ( ! is_email( $value ) || preg_match( '/[\r\n]/', $value ) ) ) {
					add_settings_error( self::OPTION, 'invalid_sender', __( 'Enter a valid sender email address.', 'plain-forms' ) );
					continue;
				}
				if ( in_array( $key, array( 'subject', 'sender_name' ), true ) && preg_match( '/[\r\n]/', $value ) ) {
					continue;
				}
				if ( 'turnstile_hostname' === $key && '' !== $value && ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/iD', $value ) ) {
					add_settings_error( self::OPTION, 'invalid_hostname', __( 'Enter a hostname without a protocol or path.', 'plain-forms' ) ); continue;
				}
				if ( 'turnstile_action' === $key && '' !== $value && ! preg_match( '/^[a-zA-Z0-9_-]{1,32}$/D', $value ) ) { continue; }
				$result[ $key ] = 'sender_email' === $key ? sanitize_email( $value ) : ( 'success_message' === $key ? sanitize_textarea_field( $value ) : sanitize_text_field( $value ) );
			}
		}
		if ( in_array( $input['clear_turnstile_secret'] ?? false, array( true, 1, '1' ), true ) ) {
			$result['turnstile_secret_key'] = '';
		}
		return $result;
	}
}
