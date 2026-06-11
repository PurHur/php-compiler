<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: str_getcsv() escape+enclosure inside quoted fields (#4173).
 *
 * php-src: ext/standard/file.c — php_fgetcsv() enclosure state machine.
 */

$row = str_getcsv('"a\"b",c');
echo strlen($row[0]), "\n";
echo $row[0], "\n";

$doubled = str_getcsv('"a""b",c');
echo strlen($doubled[0]), "\n";
echo $doubled[0], "\n";

$escaped = str_getcsv('"a\bc",c');
echo strlen($escaped[0]), "\n";
echo $escaped[0], "\n";
