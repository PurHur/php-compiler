<?php
// Repro #22271 — DateTime/DateTimeImmutable must own format constants (php-src ext/date/php_date.c).
// AOT path: defined() + bare :: fetch (ReflectionClass::getConstants is VM/compliance).
echo 'defined_dt=', defined('DateTime::ATOM') ? 'Y' : 'N', PHP_EOL;
echo 'defined_dti=', defined('DateTimeImmutable::ATOM') ? 'Y' : 'N', PHP_EOL;
echo 'defined_rfc=', defined('DateTime::RFC3339_EXTENDED') ? 'Y' : 'N', PHP_EOL;
echo 'bare_dt=', DateTime::ATOM, PHP_EOL;
echo 'bare_dti=', DateTimeImmutable::W3C, PHP_EOL;
