<?php
namespace PlainForms\Admin;

final class SettingsPage {
	public function render(): void {
		AdminApp::render( 'settings', AdminApp::settings() );
	}
}
