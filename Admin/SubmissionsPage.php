<?php
namespace PlainForms\Admin;
use PlainForms\{FormRepository, SubmissionRepository};

final class SubmissionsPage {
	public function render(): void {
		$q = Admin::query();
		$filters = array_intersect_key( $q, array_flip( array( 'form_id', 'status', 'email_status', 'receipt_status', 'delivery_failed', 's', 'from', 'to' ) ) );
		echo '<h1>Submissions</h1><form method="get"><input type="hidden" name="page" value="plain-forms-submissions"><div class="plainforms-filters">';
		$forms = array( '' => 'All forms' );
		foreach ( ( new FormRepository() )->all() as $post ) { $forms[ $post->ID ] = $post->post_title; }
		Ui::select( 'form_id', 'Form', $forms, $q['form_id'] ?? '' );
		Ui::select( 'status', 'Status', array_merge( array( '' => 'All statuses' ), array_combine( SubmissionRepository::STATUSES, SubmissionRepository::STATUSES ) ), $q['status'] ?? '' );
		Ui::select( 'email_status', 'Email delivery', array_merge( array( '' => 'All email statuses' ), array_combine( SubmissionRepository::EMAIL_STATUSES, SubmissionRepository::EMAIL_STATUSES ) ), $q['email_status'] ?? '' );
		Ui::select( 'receipt_status', 'Submitter receipt', array_merge( array( '' => 'All receipt statuses' ), array_combine( SubmissionRepository::RECEIPT_STATUSES, SubmissionRepository::RECEIPT_STATUSES ) ), $q['receipt_status'] ?? '' );
		Ui::select( 'delivery_failed', 'Delivery issues', array( '' => 'All deliveries', '1' => 'Any email failure' ), $q['delivery_failed'] ?? '' );
		Ui::input( 'from', 'From (site date)', $q['from'] ?? '', 'date' ); Ui::input( 'to', 'To (site date)', $q['to'] ?? '', 'date' );
		Ui::input( 's', 'Search submission values', $q['s'] ?? '', 'search' );
		submit_button( 'Filter', 'secondary', 'filter', false ); echo '</div></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'plainforms_admin', 'plainforms_nonce' );
		echo '<input type="hidden" name="action" value="plainforms_admin"><input type="hidden" name="operation" value="export">';
		foreach ( $filters as $key => $value ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">'; }
		submit_button( 'Export matching CSV', 'secondary', 'export', false ); echo '</form>';
		$table = new SubmissionsTable( $filters ); $table->prepare_items();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'plainforms_admin', 'plainforms_nonce' );
		echo '<input type="hidden" name="action" value="plainforms_admin"><input type="hidden" name="operation" value="bulk">';
		$table->display(); echo '</form>';
	}
}
