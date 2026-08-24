<?php
/**
 * #34476 — AOT DateTime::format('U.u') must keep microseconds (re-#26936 / #33930).
 *
 * php-src: ext/date/php_date.c — php_format_date tokens U / u
 *
 * Requires PHP_COMPILER_PROFILE=8.4 (createFromTimestamp is 8.4+).
 */
echo DateTime::createFromTimestamp(1700000000)->format('U.u'), "\n";
echo DateTimeImmutable::createFromTimestamp(1700000000.5)->format('U.u'), "\n";
echo DateTimeImmutable::createFromTimestamp(1700000000.5)->format('u'), "\n";
