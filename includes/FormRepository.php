<?php
namespace PlainForms;

final class FormRepository {
	public const POST_TYPE = 'plainforms_form';
	public const META_KEY = '_plainforms_config';

	public function register(): void {
		register_post_type( self::POST_TYPE, array(
			'labels' => array( 'name' => __( 'Plain Forms', 'plain-forms' ), 'singular_name' => __( 'Plain Forms', 'plain-forms' ) ),
			'public' => false, 'publicly_queryable' => false, 'show_ui' => false,
			'show_in_rest' => false, 'rewrite' => false, 'query_var' => false,
			'exclude_from_search' => true, 'can_export' => false, 'delete_with_user' => false,
			'supports' => array( 'title', 'revisions' ), 'map_meta_cap' => false,
			'capabilities' => array(
				'edit_post' => Capabilities::EDIT, 'read_post' => Capabilities::EDIT,
				'delete_post' => Capabilities::EDIT, 'edit_posts' => Capabilities::EDIT,
				'edit_others_posts' => Capabilities::EDIT, 'publish_posts' => Capabilities::EDIT,
				'read_private_posts' => Capabilities::EDIT, 'delete_posts' => Capabilities::EDIT,
				'create_posts' => Capabilities::EDIT,
			),
		) );
		register_post_meta( self::POST_TYPE, self::META_KEY, array(
			'type' => 'object', 'single' => true, 'show_in_rest' => false,
			'revisions_enabled' => true,
			'auth_callback' => static fn (): bool => current_user_can( Capabilities::EDIT ),
		) );
	}

	/** Internal read. Future public rendering must separately check enabled state. */
	public function find( int $id ): array|\WP_Error {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'private' !== $post->post_status ) {
			return new \WP_Error( 'form_not_found', __( 'Form not found.', 'plain-forms' ) );
		}
		$config = ( new FormSchema() )->validate( get_post_meta( $id, self::META_KEY, true ) );
		return is_wp_error( $config ) ? $config : array( 'id' => $id, 'title' => $post->post_title, 'config' => $config );
	}

	/** Trusted admin-facing write boundary; future controllers must also verify a nonce. */
	public function save( string $title, array $config, int $id = 0 ): int|\WP_Error {
		if ( ! current_user_can( Capabilities::EDIT ) ) {
			return new \WP_Error( 'forbidden', __( 'You cannot edit forms.', 'plain-forms' ) );
		}
		if ( $id < 0 || ( $id > 0 && is_wp_error( $this->find( $id ) ) ) ) {
			return new \WP_Error( 'form_not_found', __( 'Form not found or invalid.', 'plain-forms' ) );
		}
		$title = sanitize_text_field( $title );
		if ( '' === trim( $title ) ) {
			return new \WP_Error( 'invalid_title', __( 'Enter a form title.', 'plain-forms' ) );
		}
		$config = ( new FormSchema() )->validate( $config );
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		// Keep one versioned JSON-compatible object in post meta. wp_slash preserves literal backslashes.
		$saved = wp_insert_post( array(
			'ID' => $id, 'post_type' => self::POST_TYPE, 'post_status' => 'private',
			'post_title' => wp_slash( $title ), 'meta_input' => array( self::META_KEY => wp_slash( $config ) ),
		), true );
		if ( is_wp_error( $saved ) ) { return $saved; }
		if ( get_post_meta( $saved, self::META_KEY, true ) !== $config ) {
			return new \WP_Error( 'form_save_failed', __( 'The form configuration could not be saved. Please try again.', 'plain-forms' ) );
		}
		return $saved;
	}
	public function all(): array {
		return get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'private', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
	}

}
