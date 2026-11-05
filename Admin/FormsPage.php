<?php
namespace PlainForms\Admin;
use PlainForms\{FormRepository, SubmissionRepository};

final class FormsPage {
	public function render(): void {
		echo '<h1>Forms <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=plain-forms-edit' ) ) . '">Add form</a></h1>';
		echo '<table class="widefat striped plainforms-forms-table" role="table" aria-label="Forms"><thead role="rowgroup"><tr role="row">';
		foreach ( array( 'Name', 'Status', 'Submissions / new', 'Last submission', 'Reference', 'Updated', 'Actions' ) as $heading ) {
			echo '<th scope="col" role="columnheader">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody role="rowgroup">';
		$count = 0;
		$repo = new FormRepository();
		foreach ( $repo->all() as $post ) {
			$form = $repo->find( $post->ID );
			if ( is_wp_error( $form ) ) { continue; }
			$stats = class_exists( SubmissionRepository::class ) ? ( new SubmissionRepository() )->form_stats( $post->ID ) : array();
			++$count;
			echo '<tr role="row"><th scope="row" role="rowheader"><a href="' . esc_url( admin_url( 'admin.php?page=plain-forms-edit&id=' . $post->ID ) ) . '">' . esc_html( $post->post_title ) . '</a></th>';
			$cells = array(
				'Status' => $form['config']['enabled'] ? 'Enabled' : 'Disabled',
				'Submissions / new' => (string) ( $stats['total'] ?? 0 ) . ' / ' . (string) ( $stats['unread'] ?? 0 ),
				'Last submission' => $stats['latest'] ?? '—',
				'Reference' => '[plainforms_form id="' . $post->ID . '"]',
				'Updated' => $post->post_modified,
			);
			foreach ( $cells as $label => $value ) {
				echo '<td role="cell"><span class="plainforms-mobile-label" aria-hidden="true">' . esc_html( $label ) . '</span><div class="plainforms-cell-value">';
				if ( 'Reference' === $label ) { echo '<code>' . esc_html( $value ) . '</code>'; }
				else { echo esc_html( $value ); }
				echo '</div></td>';
			}
			echo '<td role="cell"><span class="plainforms-mobile-label" aria-hidden="true">Actions</span><div class="plainforms-cell-value plainforms-form-actions">';
			Ui::action( 'duplicate_form', $post->ID, 'Duplicate' );
			Ui::action( 'toggle_form', $post->ID, $form['config']['enabled'] ? 'Disable' : 'Enable' );
			Ui::action( 'delete_form', $post->ID, 'Delete form' );
			echo '</div></td></tr>';
		}
		if ( 0 === $count ) { echo '<tr role="row"><td role="cell" colspan="7" class="plainforms-forms-empty">' . esc_html__( 'No forms yet. Add a form to get started.', 'plain-forms' ) . '</td></tr>'; }
		echo '</tbody></table><p>Deleting a form preserves stored submissions. Prefer disabling forms that have received enquiries.</p>';
	}
}
