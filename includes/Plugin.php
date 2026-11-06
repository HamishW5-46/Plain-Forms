<?php
namespace PlainForms;
use PlainForms\Admin\Admin;
use PlainForms\Rest\{AdminController, SubmitController, EditorController};

final class Plugin {
	public const VERSION = '0.12.1';
	public const VERSION_OPTION = 'plainforms_version';

	public function boot(): void {
		add_action( 'init', array( new FormRepository(), 'register' ) );
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'admin_init', array( $this, 'upgrade' ) );
		add_action( 'admin_init', array( new Settings(), 'register' ) );
		add_action( 'rest_api_init', array( new AdminController(), 'register' ) );
		add_action( 'rest_api_init', array( new EditorController(), 'register' ) );
		$submit = new SubmitController();
		add_action( 'rest_api_init', array( $submit, 'register' ) );
		add_action( 'admin_post_nopriv_plainforms_submit', array( $submit, 'fallback' ) );
		add_action( 'admin_post_plainforms_submit', array( $submit, 'fallback' ) );
		( new Privacy() )->boot();
		if ( is_admin() ) { ( new Admin() )->boot(); }
	}
	public function register_assets(): void {
		$base = plugin_dir_url( dirname( __DIR__ ) . '/plain-forms.php' );
		wp_register_style( 'plain-forms', $base . 'assets/frontend.css', array(), self::VERSION );
		wp_register_script( 'plain-forms', $base . 'assets/frontend.js', array(), self::VERSION, true );
		// Cloudflare requires its canonical service script; Renderer enqueues it only for enabled forms.
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare controls versioning/caching; do not append a local version or self-host api.js.
		wp_register_script( 'plainforms-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=plainFormsTurnstileReady', array( 'plain-forms' ), null, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		register_block_type( dirname( __DIR__ ) . '/Blocks/Form' );
		add_shortcode( 'plainforms_form', static function ( array|string $attributes ): string {
			$attributes = shortcode_atts( array( 'id' => 0 ), $attributes );
			return ( new Renderer() )->render( absint( $attributes['id'] ) );
		} );
	}
	/** Ordinary frontend requests never run schema migrations. */
	public function upgrade(): void {
		if ( ! current_user_can( Capabilities::SETTINGS ) && ! current_user_can( 'activate_plugins' ) ) { return; }
		if ( Database::VERSION === get_option( Database::VERSION_OPTION ) && self::VERSION === get_option( self::VERSION_OPTION ) ) { return; }
		if ( ! Activator::install() ) {
			add_action( 'admin_notices', static function (): void {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Plain Forms database upgrade failed. Check database permissions before using forms.', 'plain-forms' ) . '</p></div>';
			} );
		}
	}
}
