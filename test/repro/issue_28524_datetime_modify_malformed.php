<?php

/**
 * #28524 — DateTime::modify('not a date') must throw DateMalformedStringException
 * under PROFILE≥8.3 (same as Immutable); procedural date_modify stays soft.
 */
error_reporting(E_ALL);
foreach ([
    ['DateTime', new DateTime('2020-01-01')],
    ['DateTimeImmutable', new DateTimeImmutable('2020-01-01')],
] as [$label, $d]) {
    try {
        $d->modify('not a date');
        echo "{$label}: no throw\n";
    } catch (Throwable $e) {
        echo "{$label}:", get_class($e), ':', $e->getMessage(), "\n";
    }
}
$proc = new DateTime('2020-01-01');
try {
    $r = @date_modify($proc, 'not a date');
    echo 'date_modify:';
    var_export($r);
    echo "\n";
} catch (Throwable $e) {
    echo 'date_modify:', get_class($e), ':', $e->getMessage(), "\n";
}
