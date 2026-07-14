<?php
// Issue #18972: time_sleep_until(null) — Z_PARAM_DOUBLE null → 0.0, past Warning + false.
error_reporting(E_ALL);
$ok = time_sleep_until(null);
$last = error_get_last();
var_export($ok);
echo "\n";
var_export($last['message'] ?? null);
echo "\n";
