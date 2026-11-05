<?php
namespace PlainForms;

final class Capabilities {
	public const MANAGE = 'plainforms_manage_forms';
	public const EDIT = 'plainforms_edit_forms';
	public const VIEW = 'plainforms_view_submissions';
	public const DELETE = 'plainforms_delete_submissions';
	public const SETTINGS = 'plainforms_manage_settings';

	public static function all(): array {
		return array( self::MANAGE, self::EDIT, self::VIEW, self::DELETE, self::SETTINGS );
	}

	public static function install(): void {
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( self::all() as $capability ) {
				$role->add_cap( $capability );
			}
		}
	}
}
