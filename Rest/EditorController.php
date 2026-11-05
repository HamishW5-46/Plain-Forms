<?php
namespace PlainForms\Rest;
use PlainForms\{Capabilities, FormRepository, FormSchema, Settings, EmailTemplates};
use PlainForms\Admin\AdminApp;

/** Private editing routes: WordPress cookie/REST nonce authentication plus granular capabilities. */
final class EditorController {
	public function register(): void {
		register_rest_route( 'plain-forms/v1', '/admin/forms', array(
			'methods' => 'POST', 'permission_callback' => static fn (): bool => current_user_can( Capabilities::EDIT ), 'callback' => array( $this, 'save_form' ),
		) );
		register_rest_route( 'plain-forms/v1', '/admin/forms/(?P<id>\d+)', array(
			'methods' => 'POST', 'permission_callback' => static fn (): bool => current_user_can( Capabilities::EDIT ), 'callback' => array( $this, 'save_form' ),
			'args' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
		) );
		register_rest_route( 'plain-forms/v1', '/admin/email-preview', array(
			'methods' => 'POST', 'permission_callback' => static fn (): bool => current_user_can( Capabilities::EDIT ), 'callback' => array( $this, 'email_preview' ),
		) );

		register_rest_route( 'plain-forms/v1', '/admin/settings', array(
			'methods' => 'POST', 'permission_callback' => static fn (): bool => current_user_can( Capabilities::SETTINGS ), 'callback' => array( $this, 'save_settings' ),
		) );
	}
	public function save_form( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( strlen( (string) $request->get_body() ) > 210000 ) { return new \WP_Error( 'invalid_form', 'The form configuration is too large.', array( 'status' => 413 ) ); }
		$data = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $data ) || ! is_string( $data['title'] ?? null ) || ! is_array( $data['config'] ?? null ) ) { return new \WP_Error( 'invalid_form', 'Enter a valid title and form configuration.', array( 'status' => 400 ) ); }
		$repo = new FormRepository(); $id = $repo->save( $data['title'], $data['config'], (int) ( $request['id'] ?? 0 ) );
		if ( is_wp_error( $id ) ) { $id->add_data( array( 'status' => 'forbidden' === $id->get_error_code() ? 403 : 400 ) ); return $id; }
		$form = $repo->find( $id );
		if ( is_wp_error( $form ) ) { return new \WP_Error( 'save_failed', 'The form could not be saved. Your changes are preserved; please try again.', array( 'status' => 500 ) ); }
		return new \WP_REST_Response( array( 'form' => $form, 'emailTemplates' => AdminApp::email_templates( $id ), 'message' => 'Form saved.' ), 200, array( 'Cache-Control' => 'no-store, private' ) );
	}
	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( strlen( (string) $request->get_body() ) > 100000 ) { return new \WP_Error( 'invalid_settings', 'The settings request is too large.', array( 'status' => 413 ) ); }
		$settings = new Settings(); $data = $settings->update( json_decode( (string) $request->get_body(), true ) );
		if ( is_wp_error( $data ) ) { return $data; }
		return new \WP_REST_Response( array_merge( AdminApp::settings(), array( 'message' => 'Settings saved.' ) ), 200, array( 'Cache-Control' => 'no-store, private' ) );
	}
	/** Render samples only. This endpoint neither sends mail nor reads stored enquiries. */
	public function email_preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( strlen( (string) $request->get_body() ) > 210000 ) { return new \WP_Error( 'invalid_template', 'The preview request is too large.', array( 'status' => 413 ) ); }
		$data = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $data ) || ! is_string( $data['title'] ?? null ) || ! in_array( $data['kind'] ?? '', EmailTemplates::KINDS, true ) || ! is_int( $data['id'] ?? 0 ) || ( $data['id'] ?? 0 ) < 0 ) { return new \WP_Error( 'invalid_template', 'Choose a valid email template.', array( 'status' => 400 ) ); }
		$config = ( new FormSchema() )->validate( $data['config'] ?? null );
		if ( is_wp_error( $config ) ) { $config->add_data( array( 'status' => 400 ) ); return $config; }
		if ( 'html' !== $config[ $data['kind'] ]['format'] ) { return new \WP_Error( 'invalid_template', 'Select HTML format to preview a template.', array( 'status' => 400 ) ); }
		$values = array();
		foreach ( $config['fields'] as $field ) {
			$value = $field['default'];
			if ( '' === $value || false === $value || array() === $value ) {
				$value = match ( $field['type'] ) {
					'email' => 'visitor@example.com', 'checkbox', 'consent' => true,
					'checkbox_group' => array( $field['options'][0]['value'] ),
					'select', 'radio' => $field['options'][0]['value'],
					'tel' => '+61 2 1234 5678', default => 'Example ' . strtolower( $field['label'] ),
				};
			}
			$values[ $field['id'] ] = $value;
		}
		$form = array( 'id' => $data['id'] ?? 0, 'title' => sanitize_text_field( $data['title'] ) ?: 'Your form', 'config' => $config );
		$result = ( new EmailTemplates() )->render( $form, $values, 0, gmdate( 'Y-m-d H:i:s' ), $data['kind'] );
		if ( is_wp_error( $result ) ) { $result->add_data( array( 'status' => 400 ) ); return $result; }
		return new \WP_REST_Response( $result, 200, array( 'Cache-Control' => 'no-store, private' ) );
	}

}
