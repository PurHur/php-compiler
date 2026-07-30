<?php
/** Repro #23960 — parse FULL zone display names incl. Greenwich Mean Time (ASCII space before AM). */
$f = new IntlDateFormatter(
    'en_US',
    IntlDateFormatter::FULL,
    IntlDateFormatter::FULL,
    'UTC',
    IntlDateFormatter::GREGORIAN
);
$formatted = $f->format(1577836800);
echo 'roundtrip=', var_export($f->parse($formatted), true), ' err=', intl_get_error_code(), "\n";
$gmt = 'Wednesday, January 1, 2020 at 12:00:00 AM Greenwich Mean Time';
echo 'gmt=', var_export($f->parse($gmt), true), ' err=', intl_get_error_code(), "\n";
echo 'msg=', intl_get_error_message(), "\n";
