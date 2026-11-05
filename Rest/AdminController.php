<?php
namespace PlainForms\Rest;
use PlainForms\{Capabilities, FormRepository};

final class AdminController {
	public function register(): void {
		register_rest_route( 'plain-forms/v1', '/forms', array(
			'methods' => 'GET', 'permission_callback' => static fn (): bool => current_user_can( Capabilities::EDIT ) || current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' ),
			'callback' => array( $this, 'forms' ),
		) );
	}
	public function forms(): \WP_REST_Response {
		$out = array(); $repo = new FormRepository();
		foreach ( $repo->all() as $post ) {
			$form = $repo->find( $post->ID );
			if ( is_wp_error( $form ) ) { continue; }
			$c = $form['config'];
			$out[] = array( 'id' => $post->ID, 'title' => $form['title'], 'description' => $c['description'], 'enabled' => $c['enabled'], 'fields' => array_map( static fn ( array $f ): array => array_intersect_key( $f, array_flip( array( 'id', 'label', 'required' ) ) ), $c['fields'] ) );
		}
		return new \WP_REST_Response( $out );
	}
}
