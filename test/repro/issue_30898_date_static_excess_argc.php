<?php

/**
 * Repro #30898 — DateTimeZone::listAbbreviations / DateTimeImmutable::createFromFormat excess argc.
 * php-src: ext/date/php_date.c
 */
foreach ([
    'abbr' => static fn () => DateTimeZone::listAbbreviations('x'),
    'cff' => static fn () => DateTimeImmutable::createFromFormat('Y-m-d', '2020-01-01', 'UTC', 'extra'),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ':', is_array($r) ? 'array('.count($r).')' : var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
$ok = DateTimeZone::listAbbreviations();
$dt = DateTimeImmutable::createFromFormat('Y-m-d', '2020-01-01');
echo 'ok=', (is_array($ok) && $dt instanceof DateTimeImmutable) ? '1' : '0', "\n";
