<?php
/** Repro #21480 — PROFILE=8.4 soft-null (no TypeError). */
error_reporting(E_ALL & ~E_DEPRECATED);
parse_str(null, $o);
echo var_export($o, true), "\n";
var_export(http_response_code(null));
echo "\n";
var_export(trigger_error(null));
echo "\n";
var_export(user_error(null));
echo "\n";
