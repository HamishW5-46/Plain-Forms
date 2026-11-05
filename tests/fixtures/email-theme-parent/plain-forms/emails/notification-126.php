<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
ob_start();
echo 'PARTIAL TEMPLATE OUTPUT';
trigger_error( 'Internal template details must not escape.', E_USER_WARNING );
