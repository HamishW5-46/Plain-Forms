<?php
namespace PlainForms\Admin;
use PlainForms\{FormRepository, FormSchema};

final class FormEditPage {
	public function render( int $id ): void {
		$form = $id ? ( new FormRepository() )->find( $id ) : array( 'id' => 0, 'title' => '', 'config' => ( new FormSchema() )->defaults() );
		if ( is_wp_error( $form ) ) { wp_die( esc_html( $form->get_error_message() ) ); }
		AdminApp::render( 'builder', array( 'form' => $form, 'defaults' => AdminApp::defaults(), 'emailTemplates' => AdminApp::email_templates( $id ) ) );
	}
}
