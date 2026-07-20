<?php
/** Repro #21480 — PROFILE=8.4 soft-null DEP+coerce (no TypeError). */
error_reporting(0);
parse_str(null, $o);
echo var_export($o, true), "\n";
var_export(http_response_code(null));
echo "\n";
var_export(trigger_error(null));
echo "\n";
var_export(user_error(null));
echo "\n";
