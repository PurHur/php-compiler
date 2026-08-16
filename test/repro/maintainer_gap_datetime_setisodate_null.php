<?php
/** Maintainer gap: DateTime::setISODate(null,…) vs Zend (ext/date/php_date.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$d = new DateTime('2024-06-15');
$d->setISODate(null, null, null);
echo 'DateTime::setISODate(null,null,null)=' . $d->format('Y-m-d') . "\n";

$i = new DateTimeImmutable('2024-06-15');
echo 'DateTimeImmutable::setISODate(null,null,null)=' . $i->setISODate(null, null, null)->format('Y-m-d') . "\n";
