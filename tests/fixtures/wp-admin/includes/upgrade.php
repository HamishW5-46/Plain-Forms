<?php
// Minimal installer boundary for isolated tests, never loaded by WordPress.
function dbDelta( string $sql ): void {
	$GLOBALS['test_schema_sql'] = $sql;
}
