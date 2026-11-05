<?php
namespace PlainForms\Admin;
use PlainForms\{Capabilities, Settings, EmailTemplates};

/** Emits only the minimum authorised data needed to mount the React admin application. */
final class AdminApp {
	public static function settings(): array {
		$settings = ( new Settings() )->get();
		$has_secret = '' !== $settings['turnstile_secret_key'];
		unset( $settings['turnstile_secret_key'] );
		return array( 'values' => $settings, 'hasSecret' => $has_secret );
	}
	public static function defaults(): array {
		$s = ( new Settings() )->get();
		return array(
			'storage' => $s['storage_enabled'], 'turnstile' => $s['turnstile_enabled'],
			'verificationReady' => '' !== $s['turnstile_site_key'] && '' !== $s['turnstile_secret_key'],
			'successMessage' => $s['success_message'],
		);
	}
	public static function email_templates( int $form_id ): array {
		return array( 'defaults' => EmailTemplates::defaults(), 'theme' => ( new EmailTemplates() )->theme_status( $form_id ) );
	}

	public static function render( string $screen, array $data ): void {
		$bootstrap = array(
			'screen' => $screen, 'data' => $data, 'nonce' => wp_create_nonce( 'wp_rest' ),
			'links' => array(
				'forms' => admin_url( 'admin.php?page=plain-forms-list' ),
				'add' => admin_url( 'admin.php?page=plain-forms-edit' ),
				'settings' => current_user_can( Capabilities::SETTINGS ) ? admin_url( 'admin.php?page=plain-forms-settings' ) : '',
				'submissions' => current_user_can( Capabilities::VIEW ) ? admin_url( 'admin.php?page=plain-forms-submissions' ) : '',
			),
		);
		echo '<div id="plainforms-admin-app" class="plainforms-admin-app" aria-label="Plain Forms"><p class="plainforms-app-loading" role="status">Loading Plain Forms…</p></div>';
		echo '<script type="application/json" id="plainforms-admin-bootstrap">' . wp_json_encode( $bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>';
		echo '<noscript><div class="notice notice-warning"><p>Enable JavaScript to use the form builder and settings interface.</p></div></noscript>';
	}
}
