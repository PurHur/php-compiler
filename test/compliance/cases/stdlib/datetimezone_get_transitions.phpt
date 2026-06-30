--TEST--
DateTimeZone::getTransitions() — DST transition list (#11211, php-src zim_DateTimeZone_getTransitions)
--FILE--
<?php
declare(strict_types=1);
$tz = new DateTimeZone('Europe/Berlin');
$t0 = strtotime('2024-01-01');
$t1 = strtotime('2024-06-01');
$trans = $tz->getTransitions($t0, $t1);
echo count($trans), "\n";
echo isset($trans[0]['offset']) ? 'has_offset' : 'no_offset', "\n";
echo isset($trans[0]['isdst']) ? 'has_isdst' : 'no_isdst', "\n";
echo isset($trans[0]['ts']) ? 'has_ts' : 'no_ts', "\n";
--EXPECT--
2
has_offset
has_isdst
has_ts
