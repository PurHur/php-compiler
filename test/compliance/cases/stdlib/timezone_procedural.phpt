--TEST--
stdlib timezone_offset_get/timezone_transitions_get/timezone_location_get (#6041, ext/date/php_date.c)
--FILE--
<?php
$tz = new DateTimeZone('Europe/Berlin');
$dt = new DateTime('2024-06-01T12:00:00', $tz);
echo timezone_offset_get($tz, $dt), "\n";

$begin = 1704067200;
$end = 1735603200;
$trans = timezone_transitions_get($tz, $begin, $end);
echo is_array($trans) ? count($trans) : 0, "\n";
echo isset($trans[0]['offset']) ? 'has_offset' : 'no_offset', "\n";
echo isset($trans[0]['isdst']) ? 'has_isdst' : 'no_isdst', "\n";

$loc = timezone_location_get($tz);
echo is_array($loc) ? ($loc['country_code'] ?? '?') : '?', "\n";
--EXPECT--
7200
3
has_offset
has_isdst
DE
