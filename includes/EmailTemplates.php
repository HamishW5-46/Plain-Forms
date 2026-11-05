<?php
namespace PlainForms;

/** Fixed theme paths, email-safe HTML and escaped placeholder/context rendering. Never evaluates editor PHP. */
final class EmailTemplates {
	public const KINDS = array( 'notification', 'receipt' );

	public static function defaults(): array {
		$frame = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>{form_title}</title></head><body style="margin:0;background-color:#f3f5f7;font-family:Arial,sans-serif;color:#243044"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 16px"><table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px"><tr><td style="padding:32px">';
		$footer = '</td></tr></table><p style="font-size:12px;color:#586274">{system.site_name}</p></td></tr></table></body></html>';
		return array(
			'notification' => $frame . '<h1 style="font-size:24px">New website enquiry</h1><p><strong>{form_title}</strong><br>Submission #{submission_id} · {system.submitted_at} (UTC)</p>{system.all_fields}{system.admin_link}' . $footer,
			'receipt' => $frame . '<h1 style="font-size:24px">Thanks for getting in touch</h1><p>We have received your enquiry through <strong>{form_title}</strong>.</p><p>Our team will be in touch if a response is needed.</p>' . $footer,
		);
	}

	public static function tokens( array $ids, string $kind, bool $subject = false ): array {
		$tokens = array_merge( $ids, array( 'form_title', 'submission_id', 'system.site_name', 'system.submitted_at' ) );
		if ( ! $subject ) {
			$tokens = array_merge( $tokens, array( 'system.site_url', 'system.all_fields' ) );
			if ( 'notification' === $kind ) { $tokens[] = 'system.admin_url'; $tokens[] = 'system.admin_link'; }
		}
		return array_unique( $tokens );
	}

	public static function unknown_tokens( string $text, array $allowed ): array {
		preg_match_all( '/\{([^{}]+)\}/', $text, $matches );
		return array_values( array_diff( $matches[1], $allowed ) );
	}

	/** Inline CSS only. Remove whole unsafe blocks rather than leaving script/style text in an email. */
	public static function sanitize( string $html ): string {
		$html = (string) preg_replace( '~<(script|style|iframe|object|embed|form)\b[^>]*>.*?(?:</\1\s*>|$)~is', '', $html );
		$common = array( 'style' => true, 'class' => true, 'id' => true, 'title' => true, 'dir' => true, 'lang' => true );
		$allowed = array();
		foreach ( array( 'html', 'head', 'body', 'title', 'div', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'blockquote', 'pre', 'code', 'ul', 'ol', 'li', 'br', 'hr' ) as $tag ) { $allowed[ $tag ] = $common; }
		foreach ( array( 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th' ) as $tag ) { $allowed[ $tag ] = $common + array( 'role' => true, 'width' => true, 'height' => true, 'align' => true, 'valign' => true, 'bgcolor' => true, 'border' => true, 'cellpadding' => true, 'cellspacing' => true, 'colspan' => true, 'rowspan' => true, 'scope' => true ); }
		$allowed['a'] = $common + array( 'href' => true, 'target' => true, 'rel' => true );
		$allowed['img'] = $common + array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'align' => true );
		$allowed['meta'] = array( 'charset' => true, 'name' => true, 'content' => true );
		return wp_kses( $html, $allowed, array( 'http', 'https', 'mailto', 'tel' ) );
	}

	/** Child before parent for each candidate; a form-specific file takes precedence over a shared one. */
	public function theme_file( int $form_id, string $kind ): array {
		if ( ! in_array( $kind, self::KINDS, true ) ) { return array(); }
		$names = array();
		if ( $form_id > 0 ) { $names[] = 'plain-forms/emails/' . $kind . '-' . $form_id . '.php'; }
		$names[] = 'plain-forms/emails/' . $kind . '.php';
		foreach ( $names as $name ) {
			$path = locate_template( array( $name ), false, false );
			if ( '' !== $path ) { return array( 'path' => $path, 'name' => $name ); }
		}
		return array();
	}
	public function theme_status( int $form_id ): array {
		$status = array();
		foreach ( self::KINDS as $kind ) { $found = $this->theme_file( $form_id, $kind ); $status[ $kind ] = $found['name'] ?? ''; }
		return $status;
	}

	/** Returns only display context: visitor strings are HTML-escaped, never raw values or settings. */
	public function context( array $form, array $values, int $id, string $timestamp, string $kind ): array {
		$title = $values['_plainforms']['title'] ?? $form['title'];
		$labels = $values['_plainforms']['labels'] ?? array_column( $form['config']['fields'], 'label', 'id' );
		$email = array( 'kind' => $kind, 'form_id' => (int) $form['id'], 'form_title' => self::display_text( $title ), 'submission_id' => $id ?: 'not stored', 'submitted_at' => esc_html( $timestamp ), 'site_name' => self::display_text( get_bloginfo( 'name' ) ), 'site_url' => esc_url( home_url( '/' ) ), 'values' => array(), 'fields' => array() );
		$table = '<table width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse">';
		foreach ( $labels as $key => $label ) {
			$text = self::display_text( Mailer::value( $values[ $key ] ?? '' ) );
			$email['values'][ $key ] = $text;
			$row = array( 'id' => $key, 'label' => self::display_text( $label ), 'value' => nl2br( $text ) );
			$email['fields'][] = $row;
			$table .= '<tr><th scope="row" align="left" valign="top" style="border-bottom:1px solid #dfe3ea">' . $row['label'] . '</th><td style="border-bottom:1px solid #dfe3ea">' . $row['value'] . '</td></tr>';
		}
		$email['all_fields'] = $table . '</table>';
		if ( 'notification' === $kind ) { $email['admin_url'] = $id ? esc_url( admin_url( 'admin.php?page=plain-forms-view&id=' . $id ) ) : ''; $email['admin_link'] = $id ? '<p><a href="' . $email['admin_url'] . '">View stored enquiry</a></p>' : ''; }
		return $email;
	}

	public function render( array $form, array $values, int $id, string $timestamp, string $kind ): array|\WP_Error {
		$config = $form['config'][ $kind ];
		$context = $this->context( $form, $values, $id, $timestamp, $kind );
		$template = self::defaults()[ $kind ]; $theme = array();
		if ( 'editor' === $config['template_source'] ) { $template = $config['template']; }
		elseif ( 'theme' === $config['template_source'] ) {
			$theme = $this->theme_file( (int) $form['id'], $kind );
			if ( $theme ) {
				try { $template = $this->include_theme( $theme['path'], $context ); }
				catch ( \Throwable $e ) { return new \WP_Error( 'email_template_failed', 'The email template could not be rendered.' ); }
			}
		}
		$tokens = array( 'form_title' => $context['form_title'], 'submission_id' => (string) $context['submission_id'], 'system.site_name' => $context['site_name'], 'system.site_url' => $context['site_url'], 'system.submitted_at' => $context['submitted_at'], 'system.all_fields' => $context['all_fields'] );
		if ( 'notification' === $kind ) { $tokens['system.admin_url'] = $context['admin_url']; $tokens['system.admin_link'] = $context['admin_link']; }
		foreach ( $context['values'] as $key => $value ) { $tokens[ $key ] = nl2br( $value ); }
		// Single-pass substitution prevents a visitor's literal {token} text being expanded again.
		$html = preg_replace_callback( '/\{([a-z][a-z0-9_.]*)\}/', static fn ( array $m ): string => $tokens[ $m[1] ] ?? '', $template );
		$html = self::sanitize( $html );
		if ( '' === trim( wp_strip_all_tags( $html ) ) && ! str_contains( $html, '<img' ) ) { return new \WP_Error( 'email_template_empty', 'The email template is empty.' ); }
		return array( 'html' => $html, 'themeTemplate' => $theme['name'] ?? '', 'fallback' => 'theme' === $config['template_source'] && ! $theme );
	}
	/** Protect literal placeholder syntax before a PHP template echoes display values. */
	private static function display_text( string $value ): string {
		return strtr( esc_html( $value ), array( '{' => '&#123;', '}' => '&#125;' ) );
	}

	private function include_theme( string $path, array $email ): string {
		$level = ob_get_level(); ob_start();
		// PHP warnings are not Throwable. A scoped handler is necessary to stop diagnostics
		// (including paths) entering email HTML or the HTTP response. Never change error_reporting.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Confined to the template include and restored in finally.
		set_error_handler( static function (): never {
			throw new \RuntimeException( 'Theme email template failed.' );
		} );
		try {
			include $path;
			while ( ob_get_level() > $level + 1 ) { ob_end_flush(); }
			if ( ob_get_level() !== $level + 1 ) { throw new \RuntimeException( 'Unexpected template output buffer.' ); }
			return (string) ob_get_clean();
		} finally {
			try {
				while ( ob_get_level() > $level ) { ob_end_clean(); }
			} finally {
				// Restore even if a theme-supplied output-buffer callback throws during cleanup.
				restore_error_handler();
			}
		}
	}
	public static function plaintext( string $html ): string {
		$text = (string) preg_replace_callback( '~<a\b[^>]*\bhref\s*=\s*([\"\'])(.*?)\1[^>]*>(.*?)</a>~is', static fn ( array $match ): string => wp_strip_all_tags( $match[3] ) . ' (' . $match[2] . ')', $html );
		$text = (string) preg_replace( '~<(?:br\s*/?|/p|/div|/h[1-6]|/tr|/li)>~i', "\n", $text );
		$text = (string) preg_replace( '~</th>~i', ': ', $text );
		$text = (string) preg_replace( '~</td>~i', "\t", $text );
		$text = (string) preg_replace( '/[ \t]+\n/', "\n", $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\n[ \t]*\n(?:[ \t]*\n)+/', "\n\n", $text ) );
	}
}
