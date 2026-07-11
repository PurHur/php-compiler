<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
var_export(ob_get_level());
echo "\n";
ob_end_clean();
$last = error_get_last();
var_export($last['message'] ?? null);
echo "\n";
