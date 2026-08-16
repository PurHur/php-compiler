<?php
/** Maintainer gap: DateTime(Immutable)::setDate(null,…) year vs Zend (ext/date/php_date.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$d = new DateTime('2024-06-15');
$d->setDate(null, null, null);
echo 'DateTime::setDate(null,null,null)=' . $d->format('Y-m-d') . "\n";

$i = new DateTimeImmutable('2024-06-15');
echo 'DateTimeImmutable::setDate(null,null,null)=' . $i->setDate(null, null, null)->format('Y-m-d') . "\n";

$y = new DateTime('2024-06-15');
$y->setDate(null, 6, 15);
echo 'DateTime::setDate(null,6,15)=' . $y->format('Y-m-d') . "\n";
