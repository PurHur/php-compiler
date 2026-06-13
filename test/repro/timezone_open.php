<?php
// Repro for #4634 — timezone_open() procedural ext/date (php_date.c).
var_dump(function_exists('timezone_open'));
var_dump(function_exists('date_default_timezone_get'));
$tz = timezone_open('UTC');
var_dump($tz instanceof DateTimeZone);
echo date_default_timezone_get(), "\n";
date_default_timezone_set('Europe/Berlin');
echo date_default_timezone_get(), "\n";
