<?php
namespace PlainForms\Admin;
use PlainForms\{SubmissionRepository, Mailer, Capabilities};

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( '\WP_List_Table' ) ) { require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'; }

final class SubmissionsTable extends \WP_List_Table {
	private array $filters;
	public function __construct( array $filters ) {
		parent::__construct( array( 'singular' => 'enquiry', 'plural' => 'enquiries', 'ajax' => false ) );
		$this->filters = $filters;
	}
	public function get_columns(): array {
		return array( 'cb' => '<input type="checkbox">', 'id' => 'ID', 'form' => 'Form', 'contact' => 'Contact', 'email' => 'Email', 'status' => 'Status', 'email_status' => 'Notification', 'receipt_status' => 'Receipt', 'created_at' => 'Submitted' );
	}
	protected function get_sortable_columns(): array {
		return array( 'id' => array( 'id', true ), 'status' => array( 'status', false ), 'email_status' => array( 'email_status', false ), 'receipt_status' => array( 'receipt_status', false ), 'created_at' => array( 'created_at', false ) );
	}
	protected function get_bulk_actions(): array {
		$actions = array( 'read' => 'Mark read', 'new' => 'Mark unread / restore', 'archived' => 'Archive', 'spam' => 'Mark spam' );
		if ( current_user_can( Capabilities::DELETE ) ) { $actions['delete'] = 'Delete permanently'; }
		return $actions;
	}
	protected function bulk_actions( $which = 'top' ): void {
		$name = 'bottom' === $which ? 'bulk_action2' : 'bulk_action';
		echo '<label class="screen-reader-text" for="' . esc_attr( $name ) . '">Bulk action</label><select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '"><option value="-1">Bulk actions</option>';
		foreach ( $this->get_bulk_actions() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>'; }
		echo '</select> <button type="submit" class="button">Apply</button>';
	}

	public function column_cb( $item ): string {
		return '<input type="checkbox" name="ids[]" value="' . esc_attr( (string) $item['id'] ) . '" aria-label="' . esc_attr( 'Select submission ' . $item['id'] ) . '">';
	}
	public function column_default( $item, $column_name ): string {
		$meta = $item['data']['_plainforms'] ?? array();
		if ( 'id' === $column_name ) {
			$url = wp_nonce_url( admin_url( 'admin.php?page=plain-forms-view&id=' . $item['id'] ), 'plainforms_read_' . $item['id'] );
			return '<a href="' . esc_url( $url ) . '">#' . esc_html( $item['id'] ) . '</a>';
		}
		if ( 'form' === $column_name ) { return esc_html( $meta['title'] ?? get_the_title( (int) $item['form_id'] ) ?: 'Deleted form #' . $item['form_id'] ); }
		if ( 'contact' === $column_name ) {
			foreach ( array( 'name', 'full_name', 'first_name' ) as $id ) { if ( isset( $item['data'][ $id ] ) ) { return esc_html( Mailer::value( $item['data'][ $id ] ) ); } }
			return '—';
		}
		if ( 'email' === $column_name ) {
			foreach ( $meta['types'] ?? array() as $id => $type ) { if ( 'email' === $type ) { return esc_html( Mailer::value( $item['data'][ $id ] ?? '' ) ); } }
			return '—';
		}
		if ( 'created_at' === $column_name ) { return esc_html( get_date_from_gmt( $item['created_at'], 'Y-m-d H:i' ) ); }
		return esc_html( $item[ $column_name ] ?? '' );
	}
	public function prepare_items(): void {
		$repo = new SubmissionRepository(); $q = Admin::query();
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->items = $repo->listing( $this->filters, 20, ( $this->get_pagenum() - 1 ) * 20, $q['orderby'] ?? 'id', $q['order'] ?? 'DESC' );
		$this->set_pagination_args( array( 'total_items' => $repo->count( $this->filters ), 'per_page' => 20 ) );
	}
}
