<?php
/** Uses the real WordPress sanitizer, mail hooks and database. All writes roll back; mail is intercepted. */
if ( 'cli' !== PHP_SAPI || '1' !== getenv( 'PLAINFORMS_RUN_TESTS' ) ) { exit; }
define( 'DISABLE_WP_CRON', true );
require '/var/www/html/wp-load.php';
use PlainForms\{FormSchema, FormRepository, SubmissionRepository, SubmissionService, Settings, EmailTemplates, Mailer};
set_error_handler( static function ( int $level, string $message, string $file, int $line ): bool {
	if ( str_contains( $file, '/plain-forms/' ) ) { throw new ErrorException( $message, 0, $level, $file, $line ); }
	return false;
} );
$checks = 0;
function email_check( bool $condition, string $message ): void {
	global $checks; if ( ! $condition ) { throw new RuntimeException( $message ); } ++$checks; echo 'PASS: ', $message, PHP_EOL;
}
$users = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $users ) { throw new RuntimeException( 'An administrator is needed.' ); }
wp_set_current_user( (int) $users[0] );
$settings = ( new Settings() )->get();
$settings['hash_ip'] = false; $settings['timing_enabled'] = false; $settings['turnstile_enabled'] = false;
$settings['storage_enabled'] = true; $settings['honeypot_enabled'] = true; $settings['debug_logging'] = false;
$settings['recipients'] = array( 'team@example.invalid' );
add_filter( 'pre_option_' . Settings::OPTION, static function () use ( &$settings ) { return $settings; } );
require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
$messages = array(); $outcomes = array(); $last_mailer = null;
// Always return a boolean: no test message can reach an external transport.
add_filter( 'pre_wp_mail', static function ( $return, $args ) use ( &$messages, &$outcomes, &$last_mailer ) {
	$last_mailer = new \PHPMailer\PHPMailer\PHPMailer( true );
	do_action_ref_array( 'phpmailer_init', array( &$last_mailer ) );
	$messages[] = $args + array( 'alternate' => $last_mailer->AltBody );
	return $outcomes ? array_shift( $outcomes ) : true;
}, PHP_INT_MAX, 2 );
$parent = static fn () => __DIR__ . '/fixtures/email-theme-parent';
$child = static fn () => __DIR__ . '/fixtures/email-theme-child';
$schema = ( new FormSchema() )->defaults();
$schema['fields'] = array(
	array( 'id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true ),
	array( 'id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
	array( 'id' => 'alternate', 'type' => 'email', 'label' => 'Other email' ),
	array( 'id' => 'message', 'type' => 'textarea', 'label' => 'Message' ),
);
$values = array( 'name' => 'Alex <img src=x onerror=alert(1)>', 'email' => 'submitter@example.invalid', 'alternate' => 'other@example.invalid', 'message' => "Hello & goodbye\nLiteral {system.admin_url}" );
$normal = ( new FormSchema() )->validate( $schema );
email_check( ! is_wp_error( $normal ) && ! $normal['receipt']['enabled'] && 'plain' === $normal['notification']['format'], 'Legacy forms retain plaintext notifications and receipts off' );
$template = new EmailTemplates(); $mailer = new Mailer();
global $wpdb; $wpdb->query( 'START TRANSACTION' ); $ids = array();
try {
	$schema['notification']['format'] = 'html'; $schema['notification']['template_source'] = 'editor';
	$schema['notification']['template'] = '<html><head><style>body{color:red}</style><script>alert(1)</script></head><body><h1>{form_title}</h1><p>{name}</p><a href="javascript:alert(1)" onclick="alert(1)">Unsafe</a>{system.all_fields}{system.admin_link}</body></html>';
	$config = ( new FormSchema() )->validate( $schema );
	email_check( ! is_wp_error( $config ) && ! str_contains( $config['notification']['template'], '<script' ) && ! str_contains( $config['notification']['template'], '<style' ) && ! str_contains( $config['notification']['template'], 'onclick=' ), 'Editor markup strips scripts, embedded CSS and event handlers before storage' );
	$form = array( 'id' => 123, 'title' => 'Enquiry & contact', 'config' => $config );
	$result = $template->render( $form, $values, 42, '2026-11-03 01:02:03', 'notification' );
	email_check( ! is_wp_error( $result ) && str_contains( $result['html'], 'Alex &lt;img' ) && ! str_contains( $result['html'], 'href="javascript:' ), 'Visitor values are escaped and dangerous protocols removed' );
	email_check( str_contains( html_entity_decode( $result['html'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'Literal {system.admin_url}' ), 'Placeholder-looking visitor text is not substituted a second time' );
	email_check( str_contains( $result['html'], 'page=plain-forms-view' ) && str_contains( $result['html'], 'Message' ), 'Notification exposes a stored admin link and labelled field table' );
	email_check( $mailer->send( $form, $values, 42, '2026-11-03 01:02:03' ), 'HTML administrator notification accepted by wp_mail' );
	$notification = end( $messages );
	email_check( in_array( 'Content-Type: text/html; charset=UTF-8', $notification['headers'], true ) && str_contains( $notification['message'], '<h1>' ), 'HTML MIME header and custom body are sent together' );
	email_check( str_contains( $notification['alternate'], 'Hello & goodbye' ) && str_contains( $notification['alternate'], 'page=plain-forms-view' ) && ! str_contains( $notification['alternate'], '<h1>' ) && '' === $last_mailer->AltBody, 'HTML gets a readable plaintext alternative and scoped mailer state' );
	$bad = $schema; $bad['notification']['template'] = '<?php echo "bad"; ?>';
	email_check( is_wp_error( ( new FormSchema() )->validate( $bad ) ), 'PHP cannot be entered in the HTML editor' );
	$bad = $schema; $bad['notification']['template'] = '<p>{missing_field}</p>';
	email_check( is_wp_error( ( new FormSchema() )->validate( $bad ) ), 'Unknown template field placeholders rejected' );
	$bad = $schema; $bad['notification']['template'] = str_repeat( 'x', 50001 );
	email_check( is_wp_error( ( new FormSchema() )->validate( $bad ) ), 'Oversized editor HTML rejected' );
	$schema['receipt']['enabled'] = true; $schema['receipt']['email_field'] = 'email';
	$schema['receipt']['template_source'] = 'editor'; $schema['receipt']['template'] = '<h1>Thanks {name}</h1><p>{form_title}</p>{system.all_fields}';
	$schema['receipt']['reply_to'] = 'help@example.invalid';
	$config = ( new FormSchema() )->validate( $schema ); $form['config'] = $config;
	email_check( ! is_wp_error( $config ) && $mailer->receipt( $form, $values, 42, '2026-11-03 01:02:03' ), 'Separate receipt custom HTML renders and sends' );
	$receipt = end( $messages );
	email_check( array( 'submitter@example.invalid' ) === $receipt['to'] && in_array( 'Reply-To: help@example.invalid', $receipt['headers'], true ), 'Receipt has exactly the validated submitter recipient and its own Reply-To' );
	email_check( ! str_contains( $receipt['message'], 'page=plain-forms-view' ), 'Receipt HTML has no internal submission link' );
	$bad = $schema; $bad['receipt']['template'] = '<p>{system.admin_url}</p>';
	email_check( is_wp_error( ( new FormSchema() )->validate( $bad ) ), 'Internal link placeholders cannot be used in receipt editor templates' );
	$bad = $schema; $bad['receipt']['email_field'] = 'name';
	email_check( is_wp_error( ( new FormSchema() )->validate( $bad ) ), 'Receipt recipient must reference a schema email field' );
	$bad = $schema; $bad['receipt']['reply_to'] = "help@example.invalid\r\nBcc: attacker@example.invalid";
	email_check( is_wp_error( ( new FormSchema() )->validate( $bad ) ), 'Receipt Reply-To header injection rejected' );
	$bad = $schema; $bad['receipt']['subject'] = "Thank you\nBcc: attacker@example.invalid";
	email_check( is_wp_error( ( new FormSchema() )->validate( $bad ) ), 'Receipt subject header injection rejected' );
	$bad_values = $values; $bad_values['email'] = "one@example.invalid,another@example.invalid"; $before = count( $messages );
	email_check( ! $mailer->receipt( $form, $bad_values, 42, '2026-11-03 01:02:03' ) && count( $messages ) === $before, 'Receipt never accepts a supplied recipient list' );
	$plain = $form; $plain['config']['notification']['format'] = 'plain';
	email_check( $mailer->send( $plain, $values, 42, '2026-11-03 01:02:03' ) && in_array( 'Content-Type: text/plain; charset=UTF-8', end( $messages )['headers'], true ) && '' === end( $messages )['alternate'], 'HTML and alternate-body hooks do not leak into a later plaintext notification' );

	add_filter( 'template_directory', $parent ); add_filter( 'stylesheet_directory', $child );
	wp_set_template_globals();
	$form['config']['notification']['template_source'] = 'theme';
	$result = $template->render( $form, $values, 42, '2026-11-03 01:02:03', 'notification' );
	email_check( str_contains( $result['html'], 'specific-child' ), 'Form-specific child theme file overrides the matching parent file' );
	$form['id'] = 124;
	$result = $template->render( $form, $values, 42, '2026-11-03 01:02:03', 'notification' );
	email_check( str_contains( $result['html'], 'parent-specific-before-child-generic' ), 'Form-specific parent file takes precedence over generic child fallback' );
	$form['id'] = 127;
	$result = $template->render( $form, $values, 42, '2026-11-03 01:02:03', 'notification' );
	email_check( str_contains( $result['html'], 'child-notification' ) && str_contains( $result['html'], 'Alex &lt;img' ), 'Shared child file receives escaped PHP context' );
	$literal_values = $values; $literal_values['name'] = 'Literal {system.all_fields}';
	$literal = $template->render( $form, $literal_values, 42, '2026-11-03 01:02:03', 'notification' );
	email_check( str_contains( html_entity_decode( $literal['html'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'Literal {system.all_fields}' ) && 1 === substr_count( $literal['html'], '<table' ), 'PHP theme values cannot expand visitor-supplied placeholder syntax' );

	$form['config']['receipt']['template_source'] = 'theme';
	$result = $template->render( $form, $values, 42, '2026-11-03 01:02:03', 'receipt' );
	email_check( str_contains( $result['html'], 'parent-receipt' ) && str_contains( $result['html'], 'no-admin-context' ), 'Receipt uses its own parent fallback and has no admin context' );
	$form['id'] = 125; $level = ob_get_level();
	$result = $template->render( $form, $values, 42, '2026-11-03 01:02:03', 'receipt' );
	email_check( is_wp_error( $result ) && $level === ob_get_level() && ! str_contains( $result->get_error_message(), 'Internal details' ), 'Theme exception returns a generic failure and cleans buffers' );
	$previous_reporting = error_reporting();
	$previous_handler = static function (): bool { return false; };
	set_error_handler( $previous_handler );
	ob_start();
	$form['id'] = 126;
	$warning_result = $template->render( $form, $values, 42, '2026-11-03 01:02:03', 'notification' );
	$warning_level = ob_get_level();
	$leaked = ob_get_clean();
	email_check( is_wp_error( $warning_result ) && $level + 1 === $warning_level, 'Theme warnings fail safely without leaking diagnostics' );
	$restored_handler = set_error_handler( static function (): bool { return false; } );
	restore_error_handler(); restore_error_handler();
	email_check( '' === $leaked && $previous_reporting === error_reporting() && $restored_handler === $previous_handler, 'Template warnings leak no output and restore the caller handler without changing error reporting' );
	$form['id'] = 128;
	$result = $template->render( $form, $values, 42, '2026-11-03 01:02:03', 'notification' );
	email_check( is_wp_error( $result ) && $level === ob_get_level() && ! str_contains( $result->get_error_message(), 'undefined' ), 'Theme runtime errors return controlled failure and clean nested partial output' );
	remove_filter( 'template_directory', $parent ); remove_filter( 'stylesheet_directory', $child );
	wp_set_template_globals();
	$missing = static fn () => __DIR__ . '/fixtures/nonexistent-email-theme';
	add_filter( 'template_directory', $missing ); add_filter( 'stylesheet_directory', $missing );
	wp_set_template_globals();
	$result = $template->render( $form, $values, 0, '2026-11-03 01:02:03', 'notification' );
	email_check( ! is_wp_error( $result ) && $result['fallback'] && ! str_contains( $result['html'], 'View stored enquiry' ), 'Missing theme file falls back to the built-in HTML and omits unstored admin links' );
	remove_filter( 'template_directory', $missing ); remove_filter( 'stylesheet_directory', $missing );
	wp_set_template_globals();
	// Independent delivery failures and resends against the actual submissions table.
	$schema['notification']['template_source'] = 'editor'; $schema['notification']['template'] = '<h1 id="saved-team">Team: {form_title}</h1>{system.all_fields}{system.admin_link}';
	$schema['receipt']['template_source'] = 'editor';
	$repo = new FormRepository(); $submissions = new SubmissionRepository(); $service = new SubmissionService();
	$id = $repo->save( 'Email integration test', $schema ); $ids[] = $id;
	email_check( $schema['notification']['template'] === $repo->find( $id )['config']['notification']['template'], 'Editor HTML and placeholders survive real post-meta storage unchanged' );
	$request = array( 'fields' => array( 'name' => 'Alex Example', 'email' => 'submitter@example.invalid', 'alternate' => 'other@example.invalid', 'message' => 'An enquiry' ) );
	$server = array( 'REMOTE_ADDR' => '192.0.2.123' );
	$outcomes = array( true, false ); $before = count( $messages );
	$response = $service->process( $id, $request, $server ); $stored_id = $response[0]['submissionId']; $row = $submissions->find( $stored_id );
	email_check( $response[0]['success'] && count( $messages ) === $before + 2, 'One accepted submission attempts both distinct emails' );
	email_check( str_contains( $messages[ $before ]['message'], 'saved-team' ) && str_contains( $messages[ $before + 1 ]['message'], 'Thanks Alex Example' ), 'Submission pipeline uses each saved editor template with accepted field values' );
	email_check( 'sent' === $row['email_status'] && 'failed' === $row['receipt_status'], 'Receipt failure does not overwrite successful administrator delivery' );
	email_check( 'email' === $row['data']['_plainforms']['receipt_email_field'] && $row['receipt_error_message'], 'Original receipt field and generic failure are retained' );
	$count = $submissions->count( array( 'form_id' => $id ) ); $before = count( $messages );
	email_check( $service->resend( $stored_id ) && count( $messages ) === $before + 1 && 'team@example.invalid' === end( $messages )['to'][0], 'Administrator resend does not also resend the receipt' );
	$before = count( $messages );
	email_check( $service->resend_receipt( $stored_id ) && count( $messages ) === $before + 1 && 'submitter@example.invalid' === end( $messages )['to'][0], 'Explicit receipt resend only targets the submitter' );
	email_check( $count === $submissions->count( array( 'form_id' => $id ) ) && 'sent' === $submissions->find( $stored_id )['receipt_status'], 'Receipt resend updates the same stored enquiry' );
	$changed = $schema; $changed['receipt']['email_field'] = 'alternate';
	$repo->save( 'Email integration test', $changed, $id ); $service->resend_receipt( $stored_id );
	email_check( array( 'submitter@example.invalid' ) === end( $messages )['to'], 'Receipt resend uses the original recipient even after the form recipient setting changes' );
	$repo->save( 'Email integration test', $schema, $id ); $outcomes = array( false, true );
	$response = $service->process( $id, $request, $server ); $row = $submissions->find( $response[0]['submissionId'] );
	email_check( $response[0]['success'] && 'failed' === $row['email_status'] && 'sent' === $row['receipt_status'], 'Stored administrator-mail failure still permits a successful receipt' );
	email_check( $submissions->count( array( 'form_id' => $id, 'delivery_failed' => '1' ) ) >= 1 && $submissions->count( array( 'form_id' => $id, 'receipt_status' => 'sent' ) ) >= 2, 'Separate receipt and combined failure filters work' );
	$removed = $changed; $removed['fields'] = array_values( array_filter( $removed['fields'], static fn ( $f ) => 'email' !== $f['id'] ) );
	$repo->save( 'Email integration test', $removed, $id );
	email_check( $service->resend_receipt( $stored_id ) && array( 'submitter@example.invalid' ) === end( $messages )['to'], 'Original receipt recipient survives removal of its field from the current form' );
	$repo->save( 'Email integration test', $schema, $id );
	$settings['storage_enabled'] = false; $outcomes = array( false, true ); $before = count( $messages );
	email_check( 503 === $service->process( $id, $request, $server )[1] && count( $messages ) === $before + 1, 'Without storage, failed administrator delivery does not send a misleading receipt' );
	$outcomes = array( true, false );
	$response = $service->process( $id, $request, $server );
	email_check( $response[0]['success'] && ! isset( $response[0]['submissionId'] ), 'Without storage, receipt failure does not reject an administrator-delivered enquiry' );
	$settings['storage_enabled'] = true; $outcomes = array();
	$receipt_only = $schema; $receipt_only['notification']['enabled'] = false; $repo->save( 'Email integration test', $receipt_only, $id ); $before = count( $messages );
	$response = $service->process( $id, $request, $server ); $receipt_row = $submissions->find( $response[0]['submissionId'] );
	email_check( $response[0]['success'] && 'sent' === $receipt_row['receipt_status'] && count( $messages ) === $before + 1 && array( 'submitter@example.invalid' ) === end( $messages )['to'], 'Stored enquiries can send a receipt while administrator notifications are disabled' );
	$settings['storage_enabled'] = false; $before = count( $messages );
	email_check( 503 === $service->process( $id, $request, $server )[1] && count( $messages ) === $before, 'Receipt-only without any owner recovery channel refuses the enquiry' );
	$settings['storage_enabled'] = true;

	$optional = $schema; $optional['fields'][1]['required'] = false; $repo->save( 'Email integration test', $optional, $id );
	$no_email = $request; $no_email['fields']['email'] = ''; $before = count( $messages );
	$response = $service->process( $id, $no_email, $server );
	email_check( $response[0]['success'] && 'skipped' === $submissions->find( $response[0]['submissionId'] )['receipt_status'] && count( $messages ) === $before + 1, 'Blank optional email skips the receipt without rejecting the enquiry' );
	email_check( ! $service->resend_receipt( $response[0]['submissionId'] ), 'A skipped receipt cannot be resent to an invented address' );
	$disabled = $schema; $disabled['receipt']['enabled'] = false; $repo->save( 'Email integration test', $disabled, $id ); $before = count( $messages );
	$response = $service->process( $id, $request, $server );
	email_check( 'disabled' === $submissions->find( $response[0]['submissionId'] )['receipt_status'] && count( $messages ) === $before + 1, 'Receipts remain opt-in for each form' );
	$old_version = static fn () => '1'; add_filter( 'pre_option_' . \PlainForms\Database::VERSION_OPTION, $old_version );
	$insert_sql = ''; $record_query = static function ( $sql ) use ( &$insert_sql ) { if ( str_contains( $sql, 'INSERT INTO `' . \PlainForms\Database::table() . '`' ) ) { $insert_sql = $sql; } return $sql; };
	add_filter( 'query', $record_query );
	$response = $service->process( $id, $request, $server );
	email_check( $response[0]['success'] && ! str_contains( $insert_sql, 'receipt_status' ), 'Legacy enquiries still store using the old columns while an upgrade is pending' );
	$repo->save( 'Email integration test', $schema, $id ); $before = count( $messages );
	email_check( 503 === $service->process( $id, $request, $server )[1] && count( $messages ) === $before, 'Receipt tracking waits safely for its required database upgrade' );
	remove_filter( 'query', $record_query ); remove_filter( 'pre_option_' . \PlainForms\Database::VERSION_OPTION, $old_version );

	$repo->save( 'Email integration test', $schema, $id ); $spam = $request; $spam['website'] = 'bot'; $before = count( $messages );
	email_check( $service->process( $id, $spam, $server )[0]['success'] && count( $messages ) === $before, 'Honeypot processing sends neither email' );
	$bad_request = $request; $bad_request['fields']['email'] = 'bad';
	email_check( 422 === $service->process( $id, $bad_request, $server )[1] && count( $messages ) === $before, 'Invalid email sends neither notification nor receipt' );
	$preview_request = new WP_REST_Request( 'POST', '/plain-forms/v1/admin/email-preview' );
	$preview_request->set_header( 'content-type', 'application/json' );
	$preview_schema = $schema; $preview_schema['receipt']['template_source'] = 'default';
	$preview_request->set_body( wp_json_encode( array( 'id' => $id, 'title' => 'Preview', 'config' => $preview_schema, 'kind' => 'receipt' ) ) );
	$before = count( $messages ); $preview = rest_get_server()->dispatch( $preview_request );
	email_check( 200 === $preview->get_status() && str_contains( $preview->get_data()['html'], 'Thanks for getting in touch' ) && count( $messages ) === $before, 'Authenticated preview renders samples without sending mail' );
	wp_set_current_user( 0 );
	email_check( 401 === rest_get_server()->dispatch( $preview_request )->get_status() && ! $service->resend_receipt( $stored_id ), 'Anonymous users cannot preview or resend receipts' );
	wp_set_current_user( (int) $users[0] );
	$deny = static function ( $caps ) { $caps[\PlainForms\Capabilities::EDIT] = false; return $caps; }; add_filter( 'user_has_cap', $deny );
	email_check( 403 === rest_get_server()->dispatch( $preview_request )->get_status(), 'Preview requires the custom form-editing capability' );
	remove_filter( 'user_has_cap', $deny );
	echo $checks, ' email template and receipt checks passed. All writes will roll back.', PHP_EOL;
} finally {
	$wpdb->query( 'ROLLBACK' ); foreach ( $ids as $id ) { if ( is_int( $id ) ) { clean_post_cache( $id ); } }
	remove_filter( 'template_directory', $parent ); remove_filter( 'stylesheet_directory', $child );
	wp_set_template_globals();
}
