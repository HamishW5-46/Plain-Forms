<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
?><h1 id="parent-receipt">Parent receipt</h1><p><?php echo $email['values']['name'] ?? ''; ?></p><p><?php echo isset( $email['admin_url'] ) ? 'unexpected-admin' : 'no-admin-context'; ?></p>
