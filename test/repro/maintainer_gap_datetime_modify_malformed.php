<?php

/**
 * DateTime(Immutable)::modify('not a date') — PROFILE≥8.3 must throw
 * DateMalformedStringException (php-src ext/date/php_date.c EH_THROW, #22663).
 */
$d = new DateTimeImmutable('2024-01-01');
try {
    var_export($d->modify('not a date'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$d2 = new DateTime('2024-01-01');
try {
    var_export($d2->modify('not a date'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo 'class_exists=', class_exists('DateMalformedStringException') ? 'Y' : 'N', "\n";
