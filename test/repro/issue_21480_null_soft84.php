<?php
/** Repro #21480 — PROFILE=8.4 soft-null (no TypeError). VM checks returns; AOT uses void hrc. */
error_reporting(0);
parse_str(null, $o);
echo var_export($o, true), "\n";
$hrc = http_response_code(null);
echo var_export($hrc, true), "\n";
$t = trigger_error(null);
echo var_export($t, true), "\n";
$u = user_error(null);
echo var_export($u, true), "\n";
