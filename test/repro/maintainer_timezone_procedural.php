<?php
// Repro for #6041 — procedural DateTimeZone helpers (ext/date/php_date.c).
echo function_exists('timezone_offset_get') ? "offset: yes\n" : "offset: no\n";
echo function_exists('timezone_transitions_get') ? "transitions: yes\n" : "transitions: no\n";
echo function_exists('timezone_location_get') ? "location: yes\n" : "location: no\n";

$tz = new DateTimeZone('Europe/Berlin');
$dt = new DateTime('2024-06-01T12:00:00', $tz);
echo timezone_offset_get($tz, $dt), "\n";
$trans = timezone_transitions_get($tz, 1704067200, 1735603200);
echo count($trans), "\n";
$loc = timezone_location_get($tz);
echo $loc['country_code'] ?? '?', "\n";
