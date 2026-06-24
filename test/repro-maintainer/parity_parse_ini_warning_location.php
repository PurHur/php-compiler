<?php
/** Maintainer repro for #11076 — parse_ini_string() syntax warning includes virtual filename/line. */
@parse_ini_string('on=foo');
$msg = error_get_last()['message'] ?? '';
echo str_contains($msg, 'Unknown on line 1') ? "OK\n" : "FAIL: {$msg}\n";
