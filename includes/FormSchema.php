<?php
namespace PlainForms;

/** Normalises stored configuration at every read as well as every write. */
final class FormSchema {
	public const VERSION = 1;
	public const TYPES = array( 'text', 'email', 'tel', 'textarea', 'select', 'radio', 'checkbox', 'checkbox_group', 'hidden', 'consent' );

	public function defaults(): array {
		return array(
			'schema_version' => self::VERSION, 'enabled' => true, 'description' => '', 'fields' => array(),
			'notification' => array( 'enabled' => true, 'recipients' => array(), 'subject' => '', 'reply_to_field' => '', 'format' => 'plain', 'template_source' => 'default', 'template' => '' ),
			'receipt' => array( 'enabled' => false, 'email_field' => '', 'subject' => 'We received your enquiry: {form_title}', 'reply_to' => '', 'format' => 'html', 'template_source' => 'default', 'template' => '' ),
			'confirmation' => array( 'message' => '' ), 'storage' => array( 'enabled' => null ), 'turnstile' => null,
		);
	}

	public static function pattern( string $pattern ): string {
		return '~(*LIMIT_MATCH=10000)(*LIMIT_RECURSION=1000)\\A(?:' . str_replace( '~', '\\~', $pattern ) . ')\\z~u';
	}

	public function validate( mixed $input ): array|\WP_Error {
		try {
			$this->need( is_array( $input ) && self::VERSION === ( $input['schema_version'] ?? null ), 'Unsupported form schema version.' );
			$this->need( ! array_diff( array_keys( $input ), array_keys( $this->defaults() ) ), 'Unknown configuration setting.' );
			$c = array_replace( $this->defaults(), $input );
			$this->need( is_bool( $c['enabled'] ) && is_string( $c['description'] ) && is_array( $c['fields'] ) && array_is_list( $c['fields'] ) && count( $c['fields'] ) <= 50, 'Invalid form configuration.' );
			$c['description'] = sanitize_textarea_field( $c['description'] );
			$this->need( null === $c['turnstile'] || is_bool( $c['turnstile'] ), 'Invalid Turnstile setting.' );
			$ids = array();
			foreach ( $c['fields'] as &$f ) {
				$this->need( is_array( $f ), 'Invalid field.' );
				$id = $f['id'] ?? '';
				$this->need( is_string( $id ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,49}$/D', $id ) && ! isset( $ids[ $id ] ) && ! in_array( $id, array( 'form_title', 'submission_id' ), true ), 'Field IDs must be unique lowercase names using letters, numbers and underscores.' );
				$ids[ $id ] = $f['type'] ?? '';
				$this->need( in_array( $ids[ $id ], self::TYPES, true ), 'Unsupported field type.' );
				$defaults = array( 'id' => $id, 'type' => '', 'label' => '', 'description' => '', 'required' => false, 'placeholder' => '', 'autocomplete' => '', 'default' => '', 'options' => array(), 'min_length' => 0, 'max_length' => 2000, 'pattern' => '' );
				$this->need( ! array_diff( array_keys( $f ), array_keys( $defaults ) ), 'Unknown field setting.' );
				$f = array_replace( $defaults, $f );
				foreach ( array( 'label', 'description', 'placeholder', 'autocomplete', 'pattern' ) as $key ) {
					$this->need( is_string( $f[ $key ] ) && strlen( $f[ $key ] ) <= 2000, 'Invalid field text.' );
					if ( 'pattern' !== $key ) { $f[ $key ] = sanitize_text_field( $f[ $key ] ); }
				}
				$this->need( '' !== $f['label'] && is_bool( $f['required'] ), 'Every field needs a label and a valid required setting.' );
				$this->need( '' === $f['autocomplete'] || 1 === preg_match( '/^[a-z0-9 -]{1,100}$/D', $f['autocomplete'] ), 'Invalid autocomplete value.' );
				$this->need( is_int( $f['min_length'] ) && is_int( $f['max_length'] ) && $f['min_length'] >= 0 && $f['max_length'] >= max( 1, $f['min_length'] ) && $f['max_length'] <= 20000, 'Invalid length limits (maximum 20000).' );
				if ( '' !== $f['pattern'] ) {
					$this->need( in_array( $f['type'], array( 'text', 'tel' ), true ) && strlen( $f['pattern'] ) <= 200 && ! preg_match( '/[()|~\x00-\x1f]/', $f['pattern'] ) && false !== @preg_match( self::pattern( $f['pattern'] ), '' ), 'Use a simple valid pattern without groups or alternatives.' );
				}
				if ( '' !== $f['pattern'] ) {
					$this->need( ! preg_match( '/[+*?}]\\+/', $f['pattern'] ), 'Possessive pattern quantifiers are not supported.' );
					preg_match_all( '/\\\\(.)/s', $f['pattern'], $escapes );
					foreach ( $escapes[1] as $escape ) {
						$this->need( ! ctype_alnum( $escape ) || in_array( $escape, array( 'd', 'D', 's', 'S', 'w', 'W', 'n', 'r', 't' ), true ), 'Use simple JavaScript/PHP-compatible pattern escapes.' );
					}
				}

				$this->need( is_array( $f['options'] ) && array_is_list( $f['options'] ) && count( $f['options'] ) <= 100, 'Invalid field options.' );
				$values = array();
				foreach ( $f['options'] as &$option ) {
					$this->need( is_array( $option ) && isset( $option['value'], $option['label'] ) && is_string( $option['value'] ) && is_string( $option['label'] ), 'Options need a value and label.' );
					$option = array( 'value' => sanitize_text_field( $option['value'] ), 'label' => sanitize_text_field( $option['label'] ) );
					$this->need( '' !== $option['value'] && '' !== $option['label'] && strlen( $option['value'] ) <= 200 && ! in_array( $option['value'], $values, true ), 'Option values must be non-empty and unique.' );
					$values[] = $option['value'];
				}
				unset( $option );
				$this->need( ! in_array( $f['type'], array( 'select', 'radio', 'checkbox_group' ), true ) || count( $values ) > 0, 'Choice fields require options.' );
				if ( in_array( $f['type'], array( 'checkbox', 'consent' ), true ) ) {
					$this->need( is_bool( $f['default'] ) || '' === $f['default'], 'Invalid checkbox default.' );
					$f['default'] = (bool) $f['default'];
					if ( 'consent' === $f['type'] ) { $f['default'] = false; }
				} elseif ( 'checkbox_group' === $f['type'] ) {
					if ( '' === $f['default'] ) { $f['default'] = array(); }
					$this->need( is_array( $f['default'] ) && array_is_list( $f['default'] ) && count( array_filter( $f['default'], 'is_string' ) ) === count( $f['default'] ) && ! array_diff( $f['default'], $values ), 'Invalid group default.' );
				} else {
					$this->need( is_string( $f['default'] ) && Text::length( $f['default'] ) <= $f['max_length'], 'Invalid field default.' );
					$f['default'] = 'textarea' === $f['type'] ? sanitize_textarea_field( $f['default'] ) : sanitize_text_field( $f['default'] );
					$this->need( ! in_array( $f['type'], array( 'select', 'radio' ), true ) || '' === $f['default'] || in_array( $f['default'], $values, true ), 'Default must be a configured option.' );
				}
			}
			unset( $f );
			$n = $c['notification'];
			$this->need( is_array( $n ), 'Invalid notification settings.' );
			$n = array_replace( $this->defaults()['notification'], $n );
			$this->need( is_bool( $n['enabled'] ) && is_array( $n['recipients'] ) && array_is_list( $n['recipients'] ) && count( $n['recipients'] ) <= 20 && is_string( $n['subject'] ) && is_string( $n['reply_to_field'] ), 'Invalid notification settings.' );
			foreach ( $n['recipients'] as $email ) { $this->need( is_string( $email ) && ! preg_match( '/[\r\n]/', $email ) && (bool) is_email( $email ), 'Enter valid notification recipients.' ); }
			$this->need( strlen( $n['subject'] ) <= 500 && ! preg_match( '/[\r\n]/', $n['subject'] ), 'Invalid email subject.' );
			$this->need( ! EmailTemplates::unknown_tokens( $n['subject'], EmailTemplates::tokens( array_keys( $ids ), 'notification', true ) ), 'Unknown subject placeholder.' );
			$this->need( '' === $n['reply_to_field'] || 'email' === ( $ids[ $n['reply_to_field'] ] ?? null ), 'Reply-To must reference an email field.' );
			$c['notification'] = $this->email_template( array_intersect_key( $n, $this->defaults()['notification'] ), array_keys( $ids ), 'notification' );
			$this->need( is_array( $c['receipt'] ), 'Invalid receipt settings.' );
			$this->need( ! array_diff( array_keys( $c['receipt'] ), array_keys( $this->defaults()['receipt'] ) ), 'Unknown receipt setting.' );
			$r = array_replace( $this->defaults()['receipt'], $c['receipt'] );
			$this->need( is_bool( $r['enabled'] ) && is_string( $r['email_field'] ) && is_string( $r['reply_to'] ) && is_string( $r['subject'] ), 'Invalid receipt settings.' );
			$this->need( ! $r['enabled'] || 'email' === ( $ids[ $r['email_field'] ] ?? null ), 'Select an email field for the receipt recipient.' );
			$this->need( '' === $r['reply_to'] || ( ! preg_match( '/[\r\n]/', $r['reply_to'] ) && is_email( $r['reply_to'] ) ), 'Enter a valid receipt Reply-To email address.' );
			$this->need( strlen( $r['subject'] ) <= 500 && ! preg_match( '/[\r\n]/', $r['subject'] ), 'Invalid receipt subject.' );
			$this->need( ! $r['enabled'] || ( '' !== trim( $r['subject'] ) && ! EmailTemplates::unknown_tokens( $r['subject'], EmailTemplates::tokens( array_keys( $ids ), 'receipt', true ) ) ), 'Enter a receipt subject using known placeholders.' );
			$c['receipt'] = $this->email_template( $r, array_keys( $ids ), 'receipt' );
			$this->need( is_array( $c['confirmation'] ) && is_string( $c['confirmation']['message'] ?? null ) && is_array( $c['storage'] ) && ( null === ( $c['storage']['enabled'] ?? null ) || is_bool( $c['storage']['enabled'] ) ), 'Invalid confirmation or storage setting.' );
			$c['confirmation'] = array( 'message' => sanitize_textarea_field( $c['confirmation']['message'] ) );
			$c['storage'] = array( 'enabled' => $c['storage']['enabled'] ?? null );
			return $c;
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'invalid_form_schema', $e->getMessage() );
		}
	}

	private function email_template( array $settings, array $ids, string $kind ): array {
		$this->need( in_array( $settings['format'], array( 'plain', 'html' ), true ) && in_array( $settings['template_source'], array( 'default', 'editor', 'theme' ), true ) && is_string( $settings['template'] ) && strlen( $settings['template'] ) <= 50000, 'Invalid email template settings (maximum 50000 bytes).' );
		$this->need( ! str_contains( $settings['template'], '<?' ), 'PHP belongs in a theme template file, not the HTML editor.' );
		// Avoid invoking HTML APIs for legacy/default empty bodies, keeping upgrades minimal.
		if ( '' !== $settings['template'] ) { $settings['template'] = EmailTemplates::sanitize( $settings['template'] ); }
		if ( $settings['enabled'] && 'html' === $settings['format'] && 'editor' === $settings['template_source'] ) {
			$this->need( '' !== trim( wp_strip_all_tags( $settings['template'] ) ) || str_contains( $settings['template'], '<img' ), 'Enter an HTML email template or select the default template.' );
			$this->need( ! EmailTemplates::unknown_tokens( $settings['template'], EmailTemplates::tokens( $ids, $kind ) ), 'Unknown or unavailable placeholder in the HTML email template.' );
		}
		return $settings;
	}

	private function need( bool $condition, string $message ): void {
		// Internal validation exception; callers convert it to WP_Error/REST data, never raw HTML.
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escape only when rendered, preserving the original validation message.
		if ( ! $condition ) { throw new \InvalidArgumentException( $message ); }
	}
}
