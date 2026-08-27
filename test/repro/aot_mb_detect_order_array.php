<?php

declare(strict_types=1);

/**
 * AOT: mb_detect_order(array) compile-time setter (#35278).
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_detect_order)
 */
mb_detect_order(['UTF-8', 'ASCII']);
var_export(mb_detect_order());
echo "\n";
mb_detect_order(['ASCII', 'UTF-8', 'ISO-8859-1']);
var_export(mb_detect_order());
echo "\n";
// String path must keep working.
mb_detect_order('UTF-8,ASCII');
var_export(mb_detect_order());
echo "\n";
