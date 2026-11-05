<?php
namespace PlainForms;
if ( 'cli' !== PHP_SAPI ) { exit; }

$base = ( new FormSchema() )->defaults();
$base['fields'] = array(
	array( 'id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'min_length' => 2, 'max_length' => 20 ),
	array( 'id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
	array( 'id' => 'topic', 'type' => 'select', 'label' => 'Topic', 'options' => array( array( 'value' => 'hello', 'label' => 'Hello' ) ) ),
	array( 'id' => 'consent', 'type' => 'consent', 'label' => 'Consent', 'required' => true ),
	array( 'id' => 'choices', 'type' => 'checkbox_group', 'label' => 'Choices', 'options' => array( array( 'value' => 'one', 'label' => 'One' ) ), 'required' => true ),
);
$config = ( new FormSchema() )->validate( $base );
check( ! is_wp_error( $config ), 'All basic field schemas normalise' );
$v = new ValidationService();
$good = array( 'name' => 'A Person', 'email' => 'test@example.org', 'topic' => 'hello', 'consent' => '1', 'choices' => array( 'one' ), 'unknown' => 'discard' );
$result = $v->validate( $config['fields'], $good );
check( ! $result['errors'] && ! isset( $result['data']['unknown'] ), 'Valid values accepted, unknown fields ignored' );
check( count( $v->validate( $config['fields'], array() )['errors'] ) === 4, 'Required text, email, consent and group validated' );
foreach ( array( 'broken', "safe@example.org\r\nBcc: other@example.org" ) as $email ) {
	check( isset( $v->validate( $config['fields'], array_replace( $good, array( 'email' => $email ) ) )['errors']['email'] ), 'Email validation rejects invalid/header-injected email' );
}
check( isset( $v->validate( $config['fields'], array_replace( $good, array( 'topic' => 'forged' ) ) )['errors']['topic'] ), 'Unknown select value rejected' );
check( isset( $v->validate( $config['fields'], array_replace( $good, array( 'choices' => array( 'forged' ) ) ) )['errors']['choices'] ), 'Unknown checkbox-group option rejected' );
check( isset( $v->validate( $config['fields'], array_replace( $good, array( 'choices' => array( array( 'nested' ) ) ) ) )['errors']['choices'] ), 'Nested checkbox-group request rejected without warnings' );
check( isset( $v->validate( $config['fields'], array_replace( $good, array( 'consent' => 'yes' ) ) )['errors']['consent'] ), 'Consent requires strict boolean value' );
check( isset( $v->validate( $config['fields'], array_replace( $good, array( 'name' => 'A' ) ) )['errors']['name'] ), 'Minimum length enforced' );
check( isset( $v->validate( $config['fields'], array_replace( $good, array( 'name' => str_repeat( 'a', 21 ) ) ) )['errors']['name'] ), 'Maximum length enforced' );
$duplicate = $base; $duplicate['fields'][] = $duplicate['fields'][0];
check( is_wp_error( ( new FormSchema() )->validate( $duplicate ) ), 'Duplicate field IDs rejected' );
$unknown = $base; $unknown['fields'][0]['id'] = 'form_title';
check( is_wp_error( ( new FormSchema() )->validate( $unknown ) ), 'System placeholder field IDs reserved' );
$unknown = $base; $unknown['notification']['reply_to_field'] = 'name';
check( is_wp_error( ( new FormSchema() )->validate( $unknown ) ), 'Reply-To must reference an email field' );
$unknown = $base; $unknown['notification']['subject'] = '{not_a_field}';
check( is_wp_error( ( new FormSchema() )->validate( $unknown ) ), 'Unknown subject placeholder rejected' );
$pattern = $base; $pattern['fields'][0]['pattern'] = '[A-Z]{2,10}';
$pconfig = ( new FormSchema() )->validate( $pattern );
check( ! is_wp_error( $pconfig ) && isset( $v->validate( $pconfig['fields'], $good )['errors']['name'] ), 'Simple full-value patterns enforced' );
$pattern['fields'][0]['pattern'] = '(a+)+';
check( is_wp_error( ( new FormSchema() )->validate( $pattern ) ), 'Nested pattern groups rejected' );
$turnstile = new Turnstile();
$s = array( 'turnstile_hostname' => 'example.org', 'turnstile_action' => 'contact' );
$response = array( 'success' => true, 'hostname' => 'example.org', 'action' => 'contact', 'challenge_ts' => gmdate( 'c' ) );
check( $turnstile->valid_response( $response, $s ), 'Turnstile valid verification response accepted' );
foreach ( array( array( 'success' => false ), array( 'hostname' => 'evil.org' ), array( 'action' => 'wrong' ), array( 'challenge_ts' => gmdate( 'c', time() - 301 ) ), array( 'challenge_ts' => 'invalid' ) ) as $change ) {
	check( ! $turnstile->valid_response( array_replace( $response, $change ), $s ), 'Turnstile rejects failure, mismatched origin/action or expired/invalid timestamp' );
}
$spam = new AntiSpam();
check( $spam->honeypot( 'filled', true ) && ! $spam->honeypot( '', true ) && ! $spam->honeypot( 'filled', false ), 'Honeypot respects enabled state and empty/populated values' );
foreach ( array( '=SUM(1,1)', '+x', '-1', '@cmd', " \t=HYPERLINK()", "\tvalue" ) as $formula ) {
	check( str_starts_with( \PlainForms\Admin\CsvExporter::safe( $formula ), "'" ), 'CSV formula/control prefix escaped' );
}
check( 'Normal enquiry' === \PlainForms\Admin\CsvExporter::safe( 'Normal enquiry' ), 'Plain CSV values unchanged' );
check( null === Privacy::cutoff( 0, 1700000000 ) && gmdate( 'Y-m-d H:i:s', 1700000000 - 30 * DAY_IN_SECONDS ) === Privacy::cutoff( 30, 1700000000 ), 'Retention calculation and forever option' );
$trimmed = $good; $trimmed['name'] = '<strong>A</strong>';
check( isset( $v->validate( $config['fields'], $trimmed )['errors']['name'] ), 'Length validation applies to sanitized values' );
$more = ( new FormSchema() )->defaults();
$more['fields'] = array(
	array( 'id' => 'phone', 'type' => 'tel', 'label' => 'Phone' ),
	array( 'id' => 'message', 'type' => 'textarea', 'label' => 'Message' ),
	array( 'id' => 'flag', 'type' => 'checkbox', 'label' => 'Flag' ),
	array( 'id' => 'context', 'type' => 'hidden', 'label' => 'Context', 'default' => 'fixed' ),
	array( 'id' => 'radio', 'type' => 'radio', 'label' => 'Radio', 'options' => array( array( 'value' => 'one', 'label' => 'One' ) ) ),
);
$mc = ( new FormSchema() )->validate( $more );
$validated = $v->validate( $mc['fields'], array( 'phone' => '+61 (2) 1234-5678', 'message' => "Line one\nLine two", 'context' => 'forged', 'flag' => '0', 'radio' => 'one' ) );
check( ! $validated['errors'] && 'fixed' === $validated['data']['context'] && false === $validated['data']['flag'] && str_contains( $validated['data']['message'], "\n" ), 'Tel, textarea, hidden, radio and checkbox values accepted safely' );
check( isset( $v->validate( $mc['fields'], array( 'phone' => 'letters', 'radio' => 'forged', 'flag' => array() ) )['errors']['phone'] ), 'Invalid telephone values rejected' );
$bad_pattern = $base; $bad_pattern['fields'][0]['pattern'] = '\\C+';
check( is_wp_error( ( new FormSchema() )->validate( $bad_pattern ) ), 'Nonportable pattern escapes rejected' );

check( str_starts_with( \PlainForms\Admin\CsvExporter::safe( "\u{FEFF}=1+1" ), "'" ), 'CSV Unicode format prefix escaped' );

$repository = new SubmissionRepository();
check( ! $repository->decode( array( 'data' => '{broken' ) )['data_valid'], 'Malformed submission JSON handled without warning' );
check( ! $repository->decode( array( 'data' => '{"name":{"nested":"bad"},"_plainforms":"bad"}' ) )['data_valid'], 'Malformed stored field/snapshot types fail safely' );
