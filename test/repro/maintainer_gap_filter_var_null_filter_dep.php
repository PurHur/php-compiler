<?php
/** Repro for #29723 — filter_var null $filter Deprecated then Unknown filter Warning. */
ini_set('error_reporting', (string) E_ALL);

$out = filter_var('1', null);
var_export($out);
echo "\n";
