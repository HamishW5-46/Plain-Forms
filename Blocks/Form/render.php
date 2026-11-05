<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// Renderer owns the complete form markup and escapes each value at its output boundary.
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- KSES post rules would remove form controls; Renderer escapes their contents and attributes.
echo ( new \PlainForms\Renderer() )->render( absint( $attributes['formId'] ?? 0 ), (bool) ( $attributes['showTitle'] ?? true ), (bool) ( $attributes['showDescription'] ?? true ) );
