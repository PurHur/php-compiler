--TEST--
stdlib timezone_location_get/timezone_transitions_get JIT lowering (#6041 phase 2, ext/date/php_date.c)
--JIT--
--FILE--
<?php
$tz = new DateTimeZone('Europe/Berlin');
$dt = new DateTime('2024-06-01T12:00:00', $tz);
echo timezone_offset_get($tz, $dt), "\n";

$loc = timezone_location_get($tz);
echo is_array($loc) ? ($loc['country_code'] ?? '?') : '?', "\n";

$trans = timezone_transitions_get(timezone_open('Europe/Berlin'), 1704067200, 1735603200);
echo is_array($trans) ? count($trans) : 0, "\n";
echo isset($trans[0]['offset']) ? 'has_offset' : 'no_offset', "\n";
echo isset($trans[0]['isdst']) ? 'has_isdst' : 'no_isdst', "\n";
--EXPECT--
7200
DE
3
has_offset
has_isdst
