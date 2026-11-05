<?php
namespace PlainForms\Admin;
use PlainForms\{Capabilities, FormRepository, SubmissionRepository, SubmissionService, Plugin};

final class Admin {
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_plainforms_admin', array( $this, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}
	/** Centralised scalar query parsing for every admin page. */
	public static function query(): array {
		$out = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter parameters; mutations verify a nonce in handle().
		foreach ( wp_unslash( $_GET ) as $key => $value ) { if ( is_string( $key ) && is_string( $value ) ) { $out[ $key ] = sanitize_text_field( $value ); } }
		return $out;
	}
	public function menu(): void {
		add_menu_page( 'Plain Forms', 'Plain Forms', Capabilities::MANAGE, 'plain-forms', array( $this, 'dashboard' ), 'dashicons-email-alt' );
		add_submenu_page( 'plain-forms', 'Dashboard', 'Dashboard', Capabilities::MANAGE, 'plain-forms', array( $this, 'dashboard' ) );
		add_submenu_page( 'plain-forms', 'Forms', 'Forms', Capabilities::EDIT, 'plain-forms-list', array( $this, 'forms' ) );
		add_submenu_page( 'plain-forms', 'Submissions', 'Submissions', Capabilities::VIEW, 'plain-forms-submissions', array( $this, 'submissions' ) );
		add_submenu_page( 'plain-forms', 'Settings', 'Settings', Capabilities::SETTINGS, 'plain-forms-settings', array( $this, 'settings' ) );
		// An empty parent registers hidden pages without breaking WordPress's hook lookup.
		add_submenu_page( '', 'Edit form', 'Edit form', Capabilities::EDIT, 'plain-forms-edit', array( $this, 'edit' ) );
		add_submenu_page( '', 'View submission', 'View submission', Capabilities::VIEW, 'plain-forms-view', array( $this, 'view' ) );
	}
	public function assets(): void {
		$page = self::query()['page'] ?? '';
		if ( ! str_starts_with( $page, 'plain-forms' ) ) { return; }
		$base = plugin_dir_url( dirname( __DIR__ ) . '/plain-forms.php' );
		wp_enqueue_script( 'plain-forms-admin', $base . 'assets/admin.js', array(), Plugin::VERSION, true );
		$react_screen = in_array( $page, array( 'plain-forms', 'plain-forms-edit', 'plain-forms-settings' ), true );
		wp_enqueue_style( 'plain-forms-admin', $base . 'assets/admin.css', $react_screen ? array( 'wp-components' ) : array(), Plugin::VERSION );
		if ( $react_screen ) {
			wp_enqueue_script( 'plain-forms-admin-app', $base . 'assets/admin-app.js', array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ), Plugin::VERSION, true );
		}
	}
	private function begin( string $cap ): void {
		$this->require_cap( $cap ); echo '<div class="wrap">';
		$notice = self::query()['notice'] ?? '';
		$messages = array( 'saved' => 'Changes saved.', 'done' => 'Action completed.', 'failed' => 'The action could not be completed. Check the form settings or delivery status.' );
		if ( isset( $messages[ $notice ] ) ) { echo '<div class="notice ' . ( 'failed' === $notice ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>'; }
	}
	public function dashboard(): void { $this->begin( Capabilities::MANAGE ); ( new DashboardPage() )->render(); echo '</div>'; }
	public function forms(): void { $this->begin( Capabilities::EDIT ); ( new FormsPage() )->render(); echo '</div>'; }
	public function edit(): void { $this->begin( Capabilities::EDIT ); ( new FormEditPage() )->render( absint( self::query()['id'] ?? 0 ) ); echo '</div>'; }
	public function submissions(): void { $this->begin( Capabilities::VIEW ); ( new SubmissionsPage() )->render(); echo '</div>'; }
	public function view(): void { $this->begin( Capabilities::VIEW ); ( new SubmissionViewPage() )->render( absint( self::query()['id'] ?? 0 ) ); echo '</div>'; }
	public function settings(): void { $this->begin( Capabilities::SETTINGS ); ( new SettingsPage() )->render(); echo '</div>'; }
	private function require_cap( string $cap ): void {
		if ( ! current_user_can( $cap ) ) { wp_die( 'Access denied.', '', array( 'response' => 403 ) ); }
	}
	public function handle(): void {
		$p = wp_unslash( $_POST );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'post' !== $method || ! is_string( $p['plainforms_nonce'] ?? null ) || ! wp_verify_nonce( $p['plainforms_nonce'], 'plainforms_admin' ) ) { wp_die( 'Invalid request.', '', array( 'response' => 403 ) ); }
		$operation = is_string( $p['operation'] ?? null ) ? $p['operation'] : '';
		$id = is_scalar( $p['id'] ?? null ) ? absint( $p['id'] ) : 0;
		$repo = new FormRepository(); $destination = 'plain-forms-list'; $ok = true;
		if ( in_array( $operation, array( 'save_form', 'duplicate_form', 'toggle_form', 'delete_form' ), true ) ) {
			$this->require_cap( Capabilities::EDIT );
			if ( 'save_form' === $operation ) {
				$raw = $p['config'] ?? '';
				$config = is_string( $raw ) && strlen( $raw ) <= 200000 ? json_decode( $raw, true ) : null;
				if ( ! is_array( $config ) || ! is_string( $p['title'] ?? null ) ) { wp_die( 'Enter a valid title and configuration. Use Back to correct the form.' ); }
				$result = $repo->save( $p['title'], $config, $id );
				if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) ); }
				$destination = 'plain-forms-edit&id=' . $result;
			} else {
				$form = $repo->find( $id );
				if ( is_wp_error( $form ) ) { wp_die( 'Form not found.' ); }
				if ( 'delete_form' === $operation ) { $ok = (bool) wp_delete_post( $id, true ); }
				elseif ( 'duplicate_form' === $operation ) { $ok = ! is_wp_error( $repo->save( $form['title'] . ' (copy)', $form['config'] ) ); }
				else { $form['config']['enabled'] = ! $form['config']['enabled']; $ok = ! is_wp_error( $repo->save( $form['title'], $form['config'], $id ) ); }
			}
		} elseif ( 'export' === $operation ) {
			$this->require_cap( Capabilities::VIEW );
			$filters = array(); foreach ( array( 'form_id', 'status', 'email_status', 'receipt_status', 'delivery_failed', 's', 'from', 'to' ) as $key ) { if ( is_string( $p[ $key ] ?? null ) ) { $filters[ $key ] = sanitize_text_field( $p[ $key ] ); } }
			( new CsvExporter() )->export( $filters ); exit;
		} else {
			$this->require_cap( Capabilities::VIEW );
			$destination = 'plain-forms-submissions'; $submissions = new SubmissionRepository();
			if ( 'bulk' === $operation ) {
				$action = is_string( $p['bulk_action'] ?? null ) && '-1' !== $p['bulk_action'] ? $p['bulk_action'] : ( $p['bulk_action2'] ?? '' );
				$ids = is_array( $p['ids'] ?? null ) ? $p['ids'] : array();
				if ( count( $ids ) > 500 || ! is_string( $action ) || ! in_array( $action, array_merge( SubmissionRepository::STATUSES, array( 'delete' ) ), true ) ) { wp_die( 'Choose a valid bulk action.' ); }
				if ( 'delete' === $action ) { $this->require_cap( Capabilities::DELETE ); }
				foreach ( $ids as $value ) { if ( is_scalar( $value ) ) { $result = 'delete' === $action ? $submissions->delete( absint( $value ) ) : $submissions->status( absint( $value ), $action ); $ok = $result && $ok; } }
			} elseif ( in_array( $operation, SubmissionRepository::STATUSES, true ) ) { $ok = $submissions->status( $id, $operation ); $destination = 'plain-forms-view&id=' . $id; }
			elseif ( 'delete' === $operation ) { $this->require_cap( Capabilities::DELETE ); $ok = $submissions->delete( $id ); }
			elseif ( 'resend_receipt' === $operation ) { $this->require_cap( Capabilities::MANAGE ); $ok = ( new SubmissionService() )->resend_receipt( $id ); $destination = 'plain-forms-view&id=' . $id; }
			elseif ( 'resend' === $operation ) { $this->require_cap( Capabilities::MANAGE ); $ok = ( new SubmissionService() )->resend( $id ); $destination = 'plain-forms-view&id=' . $id; }
			else { wp_die( 'Unknown action.', '', array( 'response' => 400 ) ); }
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . $destination . '&notice=' . ( $ok ? 'saved' : 'failed' ) ) ); exit;
	}
}
