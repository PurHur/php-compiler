<?php
/**
 * #27901 — timezone_open Reflection return DateTimeZone|false.
 */
$r = new ReflectionFunction('timezone_open');
echo 'ret=', (string) $r->getReturnType(), PHP_EOL;
$tz = timezone_open('UTC');
echo 'ok=', $tz instanceof DateTimeZone ? '1' : '0', PHP_EOL;
