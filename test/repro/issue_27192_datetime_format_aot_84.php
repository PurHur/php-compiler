<?php
/**
 * Repro #27192 — AOT DateTime::format under PHP_COMPILER_PROFILE=8.4 must not segfault.
 *
 *   PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 \
 *     ./phpc build -o /tmp/dt_fmt test/repro/issue_27192_datetime_format_aot_84.php
 *   /tmp/dt_fmt
 */
echo (new DateTime('@0'))->format('Y-m-d'), "\n";
$d = new DateTime('2024-01-15 12:00:00', new DateTimeZone('UTC'));
echo $d->format('Y-m-d'), "\n";
$di = new DateTimeImmutable('2024-06-05 08:00:00', new DateTimeZone('UTC'));
echo $di->format('Y-m-d'), "\n";
echo $di->format('Y-m-d H:i:s'), "\n";
