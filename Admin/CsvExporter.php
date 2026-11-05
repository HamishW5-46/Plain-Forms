<?php
namespace PlainForms\Admin;
use PlainForms\{Capabilities, SubmissionRepository, Mailer};

final class CsvExporter {
	public static function safe( mixed $value ): string {
		$value = Mailer::value( $value );
		// Include leading whitespace/control characters before a formula marker.
		return preg_match( '/^[\s\p{Z}\p{Cf}\x00-\x20]*[=+\-@]/u', $value ) || preg_match( '/^[\t\r\n]/', $value ) ? "'" . $value : $value;
	}
	public function export( array $filters ): void {
		if ( ! current_user_can( Capabilities::VIEW ) ) { wp_die( 'Access denied.', '', array( 'response' => 403 ) ); }
		$repo = new SubmissionRepository();
		$filters['max_id'] = $repo->maximum_id();
		$columns = array();
		// Two bounded-memory passes build a union of snapshot labels, including deleted forms/fields.
		for ( $last = 0; ; ) {
			$filters['after_id'] = $last;
			$rows = $repo->listing( $filters, 500, 0, 'id', 'ASC' );
			if ( $rows ) { $last = (int) end( $rows )['id']; }
			foreach ( $rows as $row ) { foreach ( $row['data'] as $key => $value ) {
				if ( '_plainforms' !== $key ) { $columns[ $row['form_id'] . ':' . $key ] = ( $row['data']['_plainforms']['labels'][ $key ] ?? $key ) . ' [' . $row['form_id'] . ':' . $key . ']'; }
			} }
			if ( count( $rows ) < 500 ) { break; }
		}
		nocache_headers(); header( 'Content-Type: text/csv; charset=UTF-8' ); header( 'Content-Disposition: attachment; filename="plainforms-enquiries-' . gmdate( 'Y-m-d' ) . '.csv"' ); header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) { return; }
		fputcsv( $out, array_map( array( self::class, 'safe' ), array_merge( array( 'Submission ID', 'Form ID', 'Form', 'Submitted (UTC)', 'Status', 'Email status', 'Receipt status' ), array_values( $columns ) ) ), ',', '"', '' );
		for ( $last = 0; ; ) {
			$filters['after_id'] = $last;
			$rows = $repo->listing( $filters, 500, 0, 'id', 'ASC' );
			if ( $rows ) { $last = (int) end( $rows )['id']; }
			foreach ( $rows as $row ) {
				$values = array( $row['id'], $row['form_id'], $row['data']['_plainforms']['title'] ?? '', $row['created_at'], $row['status'], $row['email_status'], $row['receipt_status'] );
				foreach ( $columns as $key => $label ) { list( $form_id, $field ) = explode( ':', $key, 2 ); $values[] = (int) $row['form_id'] === (int) $form_id ? ( $row['data'][ $field ] ?? '' ) : ''; }
				fputcsv( $out, array_map( array( self::class, 'safe' ), $values ), ',', '"', '' );
			}
			if ( count( $rows ) < 500 ) { break; }
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes php://output HTTP response stream, never a filesystem path.
		fclose( $out );
	}
}
