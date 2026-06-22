<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
var_export(ob_get_level());
echo "\n";
$ok = ob_end_flush();
$last = error_get_last();
var_export($ok);
echo "\n";
var_export($last['type'] ?? null);
echo "\n";
var_export($last['message'] ?? null);
echo "\n";
