<?php
/** Run only explicitly, from CLI inside the WordPress container. All test database writes roll back. */
if ( 'cli' !== PHP_SAPI || '1' !== getenv( 'PLAINFORMS_RUN_TESTS' ) ) { exit( 'Explicit CLI test opt-in required.' ); }
define( 'DISABLE_WP_CRON', true );
$_SERVER['HTTP_HOST'] = 'plain-forms.test';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=plain-forms-submissions';
require '/var/www/html/wp-load.php';
use PlainForms\{FormSchema, FormRepository, SubmissionRepository, SubmissionService, Settings, RateLimiter, AntiSpam, Privacy, Turnstile, Renderer, Capabilities};

set_error_handler( static function ( int $severity, string $message, string $file, int $line ): bool {
	if ( str_contains( $file, '/plain-forms/' ) ) { throw new ErrorException( $message, 0, $severity, $file, $line ); }
	return false;
} );
$checks = 0;
function verify( bool $condition, string $label ): void {
	global $checks; ++$checks;
	if ( ! $condition ) { throw new RuntimeException( 'FAILED: ' . $label ); }
	echo 'PASS: ', $label, PHP_EOL;
}
$test_settings = ( new Settings() )->get();
$test_settings['turnstile_enabled'] = false;
$test_settings['timing_enabled'] = false;
$test_settings['hash_ip'] = false;
$test_settings['debug_logging'] = false;
$test_settings['storage_enabled'] = true;
$test_settings['honeypot_enabled'] = true;
$test_settings['recipients'] = array( 'test@example.invalid' );
$test_settings['auto_delete_expired'] = true;
$test_settings['retention_days'] = 30;
add_filter( 'pre_option_plainforms_settings', static function () use ( &$test_settings ) { return $test_settings; } );
// Never allow a test message to leave this process.
$mail_result = false; $mail_calls = 0; $mail_seen = array();
add_filter( 'pre_wp_mail', static function ( $return, $atts ) use ( &$mail_result, &$mail_calls, &$mail_seen ) { ++$mail_calls; $mail_seen = $atts; return $mail_result; }, PHP_INT_MAX, 2 );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $admin ) { exit( 'No administrator available for the test.' ); }
wp_set_current_user( (int) $admin[0] );
global $wpdb;
$wpdb->query( 'START TRANSACTION' );
$test_ids = array();
try {
	$schema = ( new FormSchema() )->defaults();
	$schema['fields'] = array(
		array( 'id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true ),
		array( 'id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
		array( 'id' => 'topic', 'type' => 'select', 'label' => 'Topic', 'options' => array( array( 'value' => 'contact', 'label' => 'Contact' ) ) ),
		array( 'id' => 'consent', 'type' => 'consent', 'label' => 'Consent', 'required' => true ),
		array( 'id' => 'message', 'type' => 'textarea', 'label' => 'Message' ),
	);
	$schema['notification']['reply_to_field'] = 'email';
	$schema['notification']['subject'] = 'Test: {form_title} {name}';
	$forms = new FormRepository(); $submissions = new SubmissionRepository(); $service = new SubmissionService();
	$id = $forms->save( 'Plain Forms integration test', $schema );
	verify( is_int( $id ), 'Real WordPress form creation' ); $test_ids[] = $id;
	$round = $forms->find( $id );
	verify( ! is_wp_error( $round ) && count( $round['config']['fields'] ) === 5, 'Schema and meta round trip' );
	$html = ( new Renderer() )->render( $id );
	verify( str_contains( $html, 'aria-describedby=' ) && str_contains( $html, 'data-plainforms-form' ) && str_contains( $html, 'aria-live="polite"' ), 'Server rendering includes accessible controls and status' );
	verify( ! str_contains( $html, $test_settings['turnstile_secret_key'] ?: 'UNLIKELY_SECRET_MARKER' ), 'Renderer does not disclose verification secret' );
	$server = array( 'REMOTE_ADDR' => '192.0.2.123', 'HTTP_USER_AGENT' => 'Integration test' );
	$payload = array( 'fields' => array( 'name' => 'Example Person', 'email' => 'test@example.invalid', 'topic' => 'contact', 'consent' => '1', 'message' => "Hello\nSecond line" ) );
	$before = $submissions->count( array( 'form_id' => $id ) );
	$result = $service->process( $id, $payload, $server );
	verify( $result[0]['success'] && isset( $result[0]['submissionId'] ), 'Successful public submission stores before failed mail' );
	$row = $submissions->find( $result[0]['submissionId'] );
	verify( 'failed' === $row['email_status'] && $row['data']['email'] === 'test@example.invalid', 'Email failure retains stored values and delivery status' );
	verify( null === $row['ip_hash'] && null === $row['user_agent'] && null === $row['referer'], 'Optional connection metadata disabled' );
	verify( $mail_calls === 1 && in_array( 'Reply-To: test@example.invalid', $mail_seen['headers'], true ), 'Validated Reply-To generated' );
	$mail_result = true;
	verify( $service->resend( (int) $row['id'] ) && 'sent' === $submissions->find( (int) $row['id'] )['email_status'], 'Resend updates original delivery state' );
	verify( $submissions->count( array( 'form_id' => $id ) ) === $before + 1, 'Resend creates no duplicate enquiry' );
	$invalid = $payload; $invalid['fields']['email'] = 'invalid';
	$result = $service->process( $id, $invalid, $server );
	verify( 422 === $result[1] && isset( $result[0]['fields']['email'] ), 'Invalid email has structured field response' );
	$spam = $payload; $spam['website'] = 'spam'; $calls = $mail_calls;
	verify( $service->process( $id, $spam, $server )[0]['success'] && $mail_calls === $calls && $submissions->count( array( 'form_id' => $id ) ) === $before + 1, 'Honeypot returns success without mail or storage' );
	$anti = new AntiSpam(); $now = time();
	verify( ! $anti->timing( $id, $now, $anti->signature( $id, $now ) ) && $anti->timing( $id, $now - 3, $anti->signature( $id, $now - 3 ) ) && ! $anti->timing( $id + 1, $now - 3, $anti->signature( $id, $now - 3 ) ), 'Timing rejects instant and forged form signatures' );
	$hash = $anti->source_hash( '192.0.2.123' );
	verify( strlen( $hash ) === 64 && $anti->source_hash( 'not-an-ip' ) === null && $hash !== hash( 'sha256', '192.0.2.123' ), 'Source hash uses keyed canonical address' );
	$limiter = new RateLimiter();
	verify( $limiter->allow( $hash, $id, 2, 60 ) && $limiter->allow( $hash, $id, 2, 60 ) && ! $limiter->allow( $hash, $id, 2, 60 ) && $limiter->allow( $hash, $id + 1, 2, 60 ), 'Rate limit counts and isolates forms' );
	$key = 'plainforms_rate_' . hash( 'sha256', $hash . ':' . $id );
	set_transient( $key, array( 'count' => 99, 'until' => time() - 1 ), 60 );
	verify( $limiter->allow( $hash, $id, 2, 60 ), 'Expired rate-limit bucket recovers' );
	$test_settings['hash_ip'] = true;
	$test_settings['rate_limit_count'] = 1;
	$result = $service->process( $id, $payload, $server );
	verify( 429 === $result[1], 'Submission service enforces rate limit' );
	$test_settings['hash_ip'] = false;
	$test_settings['turnstile_enabled'] = true;
	$test_settings['turnstile_secret_key'] = 'test-secret-not-live'; $test_settings['turnstile_site_key'] = 'test-site-not-live';
	$payload['cf-turnstile-response'] = 'test-token';
	$mock_verify = false;
	add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( &$mock_verify ) {
		if ( str_contains( $url, 'challenges.cloudflare.com/turnstile/v0/siteverify' ) ) {
			return $mock_verify ? array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'success' => true, 'challenge_ts' => gmdate( 'c' ) ) ) ) : new WP_Error( 'test_network_failure', 'Test' );
		}
		return $pre;
	}, 10, 3 );
	verify( 400 === $service->process( $id, $payload, $server )[1], 'Turnstile network failure blocks submission' );
	$mock_verify = true;
	verify( true === $service->process( $id, $payload, $server )[0]['success'], 'Turnstile verified response permits submission' );
	$test_settings['turnstile_enabled'] = false;
	$test_settings['store_user_agent'] = true; $test_settings['store_referer'] = true;
	$server['HTTP_REFERER'] = 'https://example.org/contact?token=never-retain#fragment';
	$result = $service->process( $id, $payload, $server );
	$row2 = $submissions->find( $result[0]['submissionId'] );
	verify( 'https://example.org/contact' === $row2['referer'] && 'Integration test' === $row2['user_agent'], 'Optional referer strips query and fragment' );
	$privacy = new Privacy();
	$export = $privacy->export( 'test@example.invalid' );
	verify( $export['done'] && count( $export['data'] ) >= 3, 'WordPress personal data export identifies email enquiries' );
	$export_csv = new \PlainForms\Admin\CsvExporter();
	ob_start(); $export_csv->export( array( 'form_id' => $id ) ); $csv = ob_get_clean();
	verify( str_contains( $csv, 'Email [' . $id . ':email]' ) && str_contains( $csv, 'test@example.invalid' ), 'Filtered CSV includes human-readable snapshot columns and values' );
	verify( $submissions->count( array( 'form_id' => $id, 's' => 'Example Person' ) ) >= 3, 'Submission search finds useful values' );
	verify( $submissions->status( (int) $row['id'], 'archived' ) && 'archived' === $submissions->find( (int) $row['id'] )['status'] && ! $submissions->status( (int) $row['id'], 'forged' ), 'Only allowed statuses can be saved' );
	// Retention only touches test-owned expired rows: verify pre-existing rows are not eligible first.
	$cutoff = Privacy::cutoff( 30 ); $table = \PlainForms\Database::table();
	$old_existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at < %s", $cutoff ) );
	if ( 0 === $old_existing ) {
		$wpdb->update( $table, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ), array( 'id' => $row['id'] ) );
		$privacy->cleanup();
		verify( null === $submissions->find( (int) $row['id'] ) && null !== $submissions->find( (int) $row2['id'] ), 'Retention deletes expired test enquiry and preserves recent one' );
	}
	$erased = $privacy->erase( 'test@example.invalid' );
	verify( $erased['items_removed'] && $erased['done'] && 0 === count( $privacy->export( 'test@example.invalid' )['data'] ), 'WordPress personal data erasure removes matching enquiries' );

	$observed_sql = '';
	$capture_sql = static function ( string $sql ) use ( &$observed_sql ): string { $observed_sql = $sql; return $sql; };
	add_filter( 'query', $capture_sql );
	$submissions->listing( array(), 1, 0, 'id; DROP TABLE injected', 'ASC; DROP TABLE injected' );
	remove_filter( 'query', $capture_sql );
	verify( str_contains( $observed_sql, 'ORDER BY `id` DESC' ) && ! str_contains( $observed_sql, 'injected' ), 'Invalid sort column/direction fall back to strict allowlists' );
	$where = $submissions->where( array( 'from' => "2026-01-01' OR 1=1", 'status' => "new' OR 1=1", 'operator' => 'OR 1=1', 'max_id' => '5 OR 1=1' ) );
	verify( ! str_contains( $where, 'OR 1=1' ) && str_contains( $where, 'id <= 5' ), 'Operators are fixed internally and untrusted filter fragments cannot enter SQL' );

	require_once ABSPATH . 'wp-admin/includes/admin.php';
	set_current_screen( 'plain-forms_page_plain-forms-submissions' );
	$_GET = array( 'page' => 'plain-forms-submissions', 's' => 'Example', 'form_id' => (string) $id );
	ob_start(); ( new \PlainForms\Admin\SubmissionsPage() )->render(); $admin_html = ob_get_clean();
	verify( str_contains( $admin_html, 'bulk_action' ) && str_contains( $admin_html, 'Export matching CSV' ), 'WordPress list table renders filters, bulk controls and export without warnings' );
	ob_start(); ( new \PlainForms\Admin\FormsPage() )->render(); ( new \PlainForms\Admin\DashboardPage() )->render(); ( new \PlainForms\Admin\SettingsPage() )->render(); $admin_html = ob_get_clean();
	verify( str_contains( $admin_html, '"screen":"settings"' ) && ! str_contains( $admin_html, 'test-secret-not-live' ), 'Admin screens render and never expose saved Turnstile secret' );
	( new \PlainForms\Admin\Admin() )->menu();
	verify( isset( $GLOBALS['admin_page_hooks']['plain-forms'] ), 'Admin menus register without PHP warnings' );
	$_GET = array();
	// Exercise the real public REST callback for both supported request encodings.
	$rest_submit = new WP_REST_Request( 'POST', '/plain-forms/v1/forms/' . $id . '/submit' );
	$rest_submit->set_body_params( $payload );
	$rest_reply = rest_get_server()->dispatch( $rest_submit );
	verify( 200 === $rest_reply->get_status() && $rest_reply->get_data()['success'], 'REST multipart/form submission uses shared pipeline' );
	$rest_json = new WP_REST_Request( 'POST', '/plain-forms/v1/forms/' . $id . '/submit' );
	$rest_json->set_header( 'content-type', 'application/json' ); $rest_json->set_body( wp_json_encode( $payload ) );
	verify( 200 === rest_get_server()->dispatch( $rest_json )->get_status(), 'REST JSON submission uses shared pipeline' );
	$rest_json->set_body( wp_json_encode( array( 'fields' => 'invalid' ) ) );
	verify( 400 === rest_get_server()->dispatch( $rest_json )->get_status(), 'Malformed REST field structure rejected' );
	$fail_insert = static function ( $sql ) use ( $wpdb ) { return str_contains( $sql, 'INSERT INTO `' . \PlainForms\Database::table() . '`' ) ? 'SELECT plainforms_test_missing_column' : $sql; };
	add_filter( 'query', $fail_insert ); $calls = $mail_calls;
	$failure = rest_get_server()->dispatch( $rest_submit ); remove_filter( 'query', $fail_insert );
	verify( 503 === $failure->get_status() && $mail_calls === $calls && ! str_contains( wp_json_encode( $failure->get_data() ), 'missing_column' ), 'Storage failure blocks mail and returns no SQL details' );
	$test_settings['storage_enabled'] = false; $mail_result = false;
	verify( 503 === $service->process( $id, $payload, $server )[1], 'Mail failure with explicit storage opt-out returns a failure' );
	$mail_result = true;
	$no_delivery = $schema; $no_delivery['notification']['enabled'] = false;
	$forms->save( 'Delivery disabled', $no_delivery, $id );
	verify( 503 === $service->process( $id, $payload, $server )[1], 'Disabling both storage and notifications refuses enquiries' );
	$forms->save( 'Plain Forms integration test', $schema, $id ); $test_settings['storage_enabled'] = true;

	$oversize = new WP_REST_Request( 'POST', '/plain-forms/v1/forms/' . $id . '/submit' );
	$oversize->set_header( 'content-length', '150001' ); $oversize->set_body_params( $payload );
	verify( 413 === rest_get_server()->dispatch( $oversize )->get_status(), 'Oversized multipart requests rejected by content length' );
	// Nonce failure must stop before any form write.
	$die = static fn () => static function () { throw new RuntimeException( 'expected_admin_denial' ); };
	add_filter( 'wp_die_handler', $die );
	$_POST = array( 'operation' => 'save_form', 'plainforms_nonce' => 'invalid' ); $_SERVER['REQUEST_METHOD'] = 'POST';
	$denied = false;
	try { ( new \PlainForms\Admin\Admin() )->handle(); } catch ( RuntimeException $e ) { $denied = 'expected_admin_denial' === $e->getMessage(); }
	verify( $denied, 'Administrative write rejects invalid nonce' );
	// A distinct nonce field avoids collisions with WP_List_Table's bulk nonce.
	$redirect = static function () { throw new RuntimeException( 'expected_redirect' ); };
	add_filter( 'wp_redirect', $redirect );
	$_POST = array( 'operation' => 'bulk', 'plainforms_nonce' => wp_create_nonce( 'plainforms_admin' ), 'bulk_action' => 'archived', 'ids' => array( (string) $rest_reply->get_data()['submissionId'] ), '_wpnonce' => 'core-list-table-nonce' );
	try { ( new \PlainForms\Admin\Admin() )->handle(); } catch ( RuntimeException $e ) { if ( 'expected_redirect' !== $e->getMessage() ) { throw $e; } }
	verify( 'archived' === $submissions->find( (int) $rest_reply->get_data()['submissionId'] )['status'], 'Bulk status action succeeds with independent admin/list-table nonces' );
	$deny_delete = static function ( $caps ) { $caps[Capabilities::DELETE] = false; return $caps; };
	add_filter( 'user_has_cap', $deny_delete );
	$_POST['bulk_action'] = 'delete'; $denied = false;
	try { ( new \PlainForms\Admin\Admin() )->handle(); } catch ( RuntimeException $e ) { $denied = 'expected_admin_denial' === $e->getMessage(); }
	verify( $denied && null !== $submissions->find( (int) $rest_reply->get_data()['submissionId'] ), 'Bulk deletion requires separate delete capability' );
	remove_filter( 'user_has_cap', $deny_delete ); remove_filter( 'wp_redirect', $redirect );
	remove_filter( 'wp_die_handler', $die ); $_POST = array();
	$test_settings['retention_days'] = 0;
	$count_before = $submissions->count( array( 'form_id' => $id ) ); $privacy->cleanup();
	verify( $count_before === $submissions->count( array( 'form_id' => $id ) ), 'Forever retention does not delete enquiries' );
	$schema_changed = $schema; array_pop( $schema_changed['fields'] );
	verify( is_int( $forms->save( 'Updated test form', $schema_changed, $id ) ), 'Form definition can change after submission' );
	$service->resend( (int) $rest_reply->get_data()['submissionId'] );
	verify( str_contains( $mail_seen['message'], 'Message: Hello' ), 'Resend retains original field values after form fields removed' );
	$block = '<!-- wp:plain-forms/form {"formId":' . $id . '} /-->';
	verify( str_contains( do_blocks( $block ), 'Updated test form' ), 'Saved dynamic block resolves latest form definition' );
	$revisions = wp_get_post_revisions( $id );
	verify( count( $revisions ) > 0, 'Form updates retain WordPress revisions' );
	$revision = reset( $revisions );
	verify( 4 === count( get_post_meta( $revision->ID, FormRepository::META_KEY, true )['fields'] ?? array() ), 'Revision retains versioned configuration metadata' );

	wp_set_current_user( 0 );
	verify( is_wp_error( $forms->save( 'Forbidden', $schema ) ) && ! $service->resend( (int) $row2['id'] ), 'Anonymous users cannot write forms or resend' );
	$rest = new WP_REST_Request( 'GET', '/plain-forms/v1/forms' );
	verify( 401 === rest_get_server()->dispatch( $rest )->get_status(), 'Anonymous admin REST access denied' );
	wp_set_current_user( (int) $admin[0] );
	$rest = new WP_REST_Request( 'GET', '/plain-forms/v1/forms' );
	verify( 200 === rest_get_server()->dispatch( $rest )->get_status(), 'Authorised form selector endpoint works' );
	echo "{$checks} integration checks passed. All test writes will roll back.\n";
} finally {
	$wpdb->query( 'ROLLBACK' );
	foreach ( $test_ids as $id ) { clean_post_cache( $id ); }
}
