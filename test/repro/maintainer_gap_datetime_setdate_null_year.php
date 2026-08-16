<?php
/** Maintainer gap: DateTime(Immutable)::setDate(null,…) year vs Zend (ext/date/php_date.c). */
error_reporting(E_ALL);
ini_set('display_errors', '0');
set_error_handler(static function (int $no, string $str): bool {
    if (\E_DEPRECATED === $no) {
        echo 'DEP:', $str, "\n";

        return true;
    }
    echo 'ERR:', $str, "\n";

    return true;
});

$d = new DateTime('2024-06-15');
$d->setDate(null, null, null);
echo 'DateTime::setDate(null,null,null)=' . $d->format('Y-m-d') . "\n";

$i = new DateTimeImmutable('2024-06-15');
echo 'DateTimeImmutable::setDate(null,null,null)=' . $i->setDate(null, null, null)->format('Y-m-d') . "\n";

$y = new DateTime('2024-06-15');
$y->setDate(null, 6, 15);
echo 'DateTime::setDate(null,6,15)=' . $y->format('Y-m-d') . "\n";
