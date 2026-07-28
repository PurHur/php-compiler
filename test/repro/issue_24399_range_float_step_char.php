<?php
/** Repro #24399 — float step forces numeric path for letter endpoints (php-src array.c php_range). */
var_export(range('a', 'e', 1.5));
echo "\n";
var_export(range('A', 'C', 1.5));
echo "\n";
var_export(range('a', 'e', 1));
echo "\n";
