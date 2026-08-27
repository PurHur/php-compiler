<?php
// AOT: mb_detect_order() array setter (#35278 leftover of #13100/#29920).
// php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_detect_order)
mb_detect_order(['UTF-8', 'ASCII']);
var_export(mb_detect_order());
echo "\n";
mb_detect_order(['ASCII', 'UTF-8', 'ISO-8859-1']);
var_export(mb_detect_order());
echo "\n";
// String CSV path must keep working.
mb_detect_order('UTF-8,ASCII');
var_export(mb_detect_order());
echo "\n";
