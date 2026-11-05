<?php
namespace PlainForms\Admin;

final class Ui {
	public static function input( string $name, string $label, mixed $value, string $type = 'text', string $help = '' ): void {
		$id = 'plainforms-' . sanitize_html_class( $name );
		echo '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
		if ( 'textarea' === $type ) {
			echo '<textarea class="large-text" rows="5" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
		} else {
			echo '<input class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		if ( $help ) { echo '<br><span class="description">' . esc_html( $help ) . '</span>'; }
		echo '</p>';
	}
	public static function checkbox( string $name, string $label, bool $value ): void {
		echo '<p><input type="hidden" name="' . esc_attr( $name ) . '" value="0"><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $value, true, false ) . '> ' . esc_html( $label ) . '</label></p>';
	}
	public static function select( string $name, string $label, array $options, mixed $value ): void {
		echo '<p><label>' . esc_html( $label ) . ' <select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $key => $text ) { echo '<option value="' . esc_attr( (string) $key ) . '" ' . selected( (string) $value, (string) $key, false ) . '>' . esc_html( $text ) . '</option>'; }
		echo '</select></label></p>';
	}
	public static function action( string $operation, int $id, string $label ): void {
		echo '<form class="plainforms-inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		wp_nonce_field( 'plainforms_admin', 'plainforms_nonce' );
		echo '<input type="hidden" name="action" value="plainforms_admin"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '"><button class="button" type="submit" ' . ( 'delete_form' === $operation || 'delete' === $operation ? 'data-confirm="Permanently delete? This cannot be undone."' : '' ) . '>' . esc_html( $label ) . '</button></form> ';
	}
}
