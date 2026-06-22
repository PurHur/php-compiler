<?php
/** Maintainer repro for #9260 — parse_ini_string() literal false $process_sections. */
// FAIL (VM before fix): literal false compiled as int 0
var_export(parse_ini_string("a=1\nb=2", false, INI_SCANNER_NORMAL));
echo "\n";

// OK: variable bool
$ps = false;
var_export(parse_ini_string("a=1\nb=2", $ps, INI_SCANNER_NORMAL));
echo "\n";

// literal true for sections
var_export(parse_ini_string("a=1\n[sec]\nb=2", true, INI_SCANNER_NORMAL));
echo "\n";
