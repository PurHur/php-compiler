<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: str_getcsv('') must yield array(0 => NULL).
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(str_getcsv)
 */

$row = str_getcsv('');
echo 'count=', count($row), ' type=', gettype($row[0] ?? null), "\n";

$comma = str_getcsv(',');
echo 'comma_count=', count($comma), ' types=', gettype($comma[0]), ',', gettype($comma[1]), "\n";
