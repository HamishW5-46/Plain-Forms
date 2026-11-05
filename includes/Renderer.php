<?php
namespace PlainForms;

final class Renderer {
	public function render( int $id, bool $title = true, bool $description = true ): string {
		$form = ( new FormRepository() )->find( $id );
		if ( is_wp_error( $form ) || ! $form['config']['enabled'] || ! $form['config']['fields'] ) { return '<p role="status">This form is currently unavailable.</p>'; }
		$s = ( new Settings() )->get(); $c = $form['config'];
		if ( ! ( $c['storage']['enabled'] ?? $s['storage_enabled'] ) && ! $c['notification']['enabled'] ) { return '<p role="status">This form is currently unavailable.</p>'; }
		$turnstile = $c['turnstile'] ?? $s['turnstile_enabled'];
		$base = wp_unique_id( 'plainforms-form-' );
		wp_enqueue_style( 'plain-forms' ); wp_enqueue_script( 'plain-forms' );
		if ( $turnstile ) { wp_enqueue_script( 'plainforms-turnstile' ); }
		ob_start();
		echo '<div class="plainforms-forms">';
		if ( $title ) { echo '<h2>' . esc_html( $form['title'] ) . '</h2>'; }
		if ( $description && $c['description'] ) { echo '<p>' . nl2br( esc_html( $c['description'] ) ) . '</p>'; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-plainforms-form data-endpoint="' . esc_url( rest_url( 'plain-forms/v1/forms/' . $id . '/submit' ) ) . '" data-start-url="' . esc_url( rest_url( 'plain-forms/v1/forms/' . $id . '/start' ) ) . '" data-turnstile="' . ( $turnstile ? '1' : '0' ) . '" aria-label="' . esc_attr( $form['title'] ) . '">';
		echo '<input type="hidden" name="action" value="plainforms_submit"><input type="hidden" name="form_id" value="' . esc_attr( (string) $id ) . '">';
		$stamp = time();
		echo '<input type="hidden" name="started" value="' . esc_attr( (string) $stamp ) . '"><input type="hidden" name="signature" value="' . esc_attr( ( new AntiSpam() )->signature( $id, $stamp ) ) . '">';
		echo '<div hidden aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
		echo '<div class="plainforms-error-summary" tabindex="-1" role="alert" hidden></div>';
		foreach ( $c['fields'] as $f ) { $this->field( $f, $base . '-' . $f['id'] ); }
		if ( $turnstile ) {
			echo '<div class="plainforms-turnstile" data-sitekey="' . esc_attr( $s['turnstile_site_key'] ) . '" data-action="' . esc_attr( $s['turnstile_action'] ) . '"></div>';
			if ( ! $s['turnstile_site_key'] ) { echo '<p role="status">This form’s verification is temporarily unavailable. Please contact the site administrator.</p>'; }
		}
		echo '<button type="submit" class="wp-element-button plainforms-submit">Send enquiry</button><p class="plainforms-status" role="status" aria-live="polite" aria-atomic="true"></p>';
		if ( $turnstile ) { echo '<noscript><p>JavaScript is required for this form’s spam verification. Please enable JavaScript or use another contact method listed on this website.</p></noscript>'; }
		else { echo '<noscript><p>You can submit this form without JavaScript. A confirmation page will appear after submission.</p></noscript>'; }
		echo '</form></div>';
		return (string) ob_get_clean();
	}

	private function field( array $f, string $id ): void {
		$t = $f['type']; $name = 'fields[' . $f['id'] . ']'; $default = $f['default'];
		if ( 'hidden' === $t ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $default ) . '">'; return; }
		$group = in_array( $t, array( 'radio', 'checkbox_group' ), true );
		$bool = in_array( $t, array( 'checkbox', 'consent' ), true );
		echo '<' . ( $group ? 'fieldset' : 'div' ) . ' class="plainforms-field" data-field="' . esc_attr( $f['id'] ) . '" data-required="' . ( $f['required'] ? '1' : '0' ) . '" data-type="' . esc_attr( $t ) . '">';
		$label = esc_html( $f['label'] ) . ( $f['required'] ? ' <span class="plainforms-required">(required)</span>' : '' );
		if ( $group ) { echo '<legend>' . wp_kses_post( $label ) . '</legend>'; }
		elseif ( ! $bool ) { echo '<label for="' . esc_attr( $id ) . '">' . wp_kses_post( $label ) . '</label>'; }
		$attrs = array( 'aria-describedby' => $id . '-help ' . $id . '-error' );
		if ( $f['required'] && 'checkbox_group' !== $t ) { $attrs['required'] = 'required'; $attrs['aria-required'] = 'true'; }
		if ( $group ) {
			foreach ( $f['options'] as $i => $o ) {
				$oid = $id . '-' . $i;
				$checked = 'radio' === $t ? $default === $o['value'] : in_array( $o['value'], $default, true );
				echo '<label class="plainforms-choice" for="' . esc_attr( $oid ) . '"><input id="' . esc_attr( $oid ) . '" type="' . ( 'radio' === $t ? 'radio' : 'checkbox' ) . '" name="' . esc_attr( $name . ( 'checkbox_group' === $t ? '[]' : '' ) ) . '" value="' . esc_attr( $o['value'] ) . '"';
				$this->attributes( $attrs );
				echo checked( $checked, true, false ) . '> ' . esc_html( $o['label'] ) . '</label>';
			}
		} elseif ( $bool ) {
			echo '<label for="' . esc_attr( $id ) . '"><input id="' . esc_attr( $id ) . '" type="checkbox" name="' . esc_attr( $name ) . '" value="1"';
			$this->attributes( $attrs );
			echo checked( $default, true, false ) . '> ' . wp_kses_post( $label ) . '</label>';
		} elseif ( 'select' === $t ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"';
			$this->attributes( $attrs );
			echo '><option value="">Please select</option>';
			foreach ( $f['options'] as $o ) { echo '<option value="' . esc_attr( $o['value'] ) . '"' . selected( $default, $o['value'], false ) . '>' . esc_html( $o['label'] ) . '</option>'; }
			echo '</select>';
		} else {
			$attrs['minlength'] = (string) $f['min_length']; $attrs['maxlength'] = (string) $f['max_length']; $attrs['placeholder'] = $f['placeholder'];
			if ( $f['autocomplete'] ) { $attrs['autocomplete'] = $f['autocomplete']; }
			if ( $f['pattern'] ) { $attrs['data-pattern'] = $f['pattern']; }
			if ( 'textarea' === $t ) { echo '<textarea rows="6" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"';
			$this->attributes( $attrs );
			echo '>' . esc_textarea( $default ) . '</textarea>'; }
			else { echo '<input type="' . esc_attr( $t ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $default ) . '"';
			$this->attributes( $attrs );
			echo '>'; }
		}
		echo '<span class="plainforms-help" id="' . esc_attr( $id . '-help' ) . '">' . esc_html( $f['description'] ) . '</span><span class="plainforms-error" id="' . esc_attr( $id . '-error' ) . '"></span></' . ( $group ? 'fieldset' : 'div' ) . '>';
	}
	/** Internal attribute names and raw values are escaped once at the final output boundary. */
	private function attributes( array $attributes ): void {
		foreach ( $attributes as $name => $value ) {
			echo ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}
	}

}
