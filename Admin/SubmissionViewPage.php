<?php
namespace PlainForms\Admin;
use PlainForms\{SubmissionRepository, Settings, Mailer, Capabilities};

final class SubmissionViewPage {
	public function render( int $id ): void {
		$repo = new SubmissionRepository(); $row = $repo->find( $id );
		if ( ! $row ) { wp_die( 'Submission not found.', '', array( 'response' => 404 ) ); }
		$q = Admin::query();
		if ( 'new' === $row['status'] && ( new Settings() )->get()['mark_read_on_view'] && wp_verify_nonce( $q['_wpnonce'] ?? '', 'plainforms_read_' . $id ) ) { $repo->status( $id, 'read' ); $row['status'] = 'read'; }
		echo '<h1>Submission #' . esc_html( (string) $id ) . '</h1><dl class="plainforms-detail">';
		$metadata = array( 'Form' => $row['data']['_plainforms']['title'] ?? 'Form #' . $row['form_id'], 'Status' => $row['status'], 'Notification delivery' => $row['email_status'], 'Submitter receipt' => $row['receipt_status'], 'Submitted' => get_date_from_gmt( $row['created_at'], 'Y-m-d H:i:s' ), 'Referer' => $row['referer'] ?: 'Not retained', 'User agent' => $row['user_agent'] ?: 'Not retained' );
		if ( $row['receipt_error_message'] ) { $metadata['Receipt delivery note'] = $row['receipt_error_message']; }
		if ( $row['error_message'] ) { $metadata['Delivery note'] = $row['error_message']; }
		foreach ( $metadata as $label => $value ) { echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>'; }
		if ( ! $row['data_valid'] ) { echo '<dt>Data warning</dt><dd>Stored values could not be decoded.</dd>'; }
		foreach ( $row['data'] as $key => $value ) {
			if ( '_plainforms' === $key ) { continue; }
			echo '<dt>' . esc_html( $row['data']['_plainforms']['labels'][ $key ] ?? $key ) . '</dt><dd>' . esc_html( Mailer::value( $value ) ) . '</dd>';
		}
		echo '</dl>';
		foreach ( array( 'read' => 'Mark read', 'new' => 'Mark unread / restore', 'archived' => 'Archive', 'spam' => 'Spam' ) as $action => $label ) { Ui::action( $action, $id, $label ); }
		if ( current_user_can( Capabilities::MANAGE ) ) { Ui::action( 'resend', $id, 'Resend notification' ); if ( in_array( $row['receipt_status'], array( 'pending', 'sent', 'failed' ), true ) ) { Ui::action( 'resend_receipt', $id, 'Resend submitter receipt' ); } }
		if ( current_user_can( Capabilities::DELETE ) ) { Ui::action( 'delete', $id, 'Delete permanently' ); }
	}
}
