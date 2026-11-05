<?php
namespace PlainForms;

final class ValidationService {
	/** Accept only schema fields and exact scalar/list types; unknown visitor fields are ignored. */
	public function validate( array $fields, array $input ): array {
		$data = array(); $errors = array();
		foreach ( $fields as $f ) {
			$id = $f['id']; $type = $f['type'];
			$v = $input[ $id ] ?? ( 'checkbox_group' === $type ? array() : '' );
			$error = '';
			if ( in_array( $type, array( 'checkbox', 'consent' ), true ) ) {
				if ( ! in_array( $v, array( '', false, true, 0, 1, '0', '1' ), true ) ) { $error = 'Choose a valid value.'; }
				$v = in_array( $v, array( true, 1, '1' ), true );
			} elseif ( 'checkbox_group' === $type ) {
				if ( ! is_array( $v ) || ! array_is_list( $v ) || count( $v ) > 100 || count( array_filter( $v, 'is_string' ) ) !== count( $v ) ) { $error = 'Choose valid options.'; $v = array(); }
				elseif ( array_diff( $v, array_column( $f['options'], 'value' ) ) ) { $error = 'Choose valid options.'; }
				$v = array_values( array_unique( $v ) );
			} elseif ( ! is_string( $v ) || strlen( $v ) > 80000 ) { $error = 'Enter a valid value.'; $v = ''; }
			else {
				$v = trim( $v );
				if ( 'hidden' === $type ) { $v = $f['default']; }
				if ( 'email' === $type && '' !== $v && ( preg_match( '/[\r\n]/', $v ) || ! is_email( $v ) ) ) { $error = 'Please enter a valid email address.'; }
				$v = 'email' === $type ? sanitize_email( $v ) : ( 'textarea' === $type ? sanitize_textarea_field( $v ) : sanitize_text_field( $v ) );
				if ( '' !== $v ) {
					if ( Text::length( $v ) < $f['min_length'] ) { $error = 'This value is too short.'; }
					if ( Text::length( $v ) > $f['max_length'] ) { $error = 'This value is too long.'; }
					if ( 'tel' === $type && ! preg_match( '/^[0-9+().\s#xXextEXT-]+$/uD', $v ) ) { $error = 'Please enter a valid phone number.'; }
					if ( in_array( $type, array( 'select', 'radio' ), true ) && ! in_array( $v, array_column( $f['options'], 'value' ), true ) ) { $error = 'Choose a valid option.'; }
					if ( '' !== $f['pattern'] && 1 !== preg_match( FormSchema::pattern( $f['pattern'] ), $v ) ) { $error = 'Please use the requested format.'; }
				}
			}
			if ( $f['required'] && ( '' === $v || false === $v || array() === $v ) ) { $error = 'This field is required.'; }
			if ( '' !== $error ) { $errors[ $id ] = $error; }
			$data[ $id ] = $v;
		}
		return array( 'data' => $data, 'errors' => $errors );
	}
}
