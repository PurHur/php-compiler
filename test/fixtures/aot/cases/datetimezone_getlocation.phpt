--TEST--
AOT: DateTimeZone::getLocation() / timezone_location_get() (#33727)
--FILE--
<?php
$berlin = new DateTimeZone('Europe/Berlin');
$oop = $berlin->getLocation();
$proc = timezone_location_get($berlin);
echo is_array($oop) ? ($oop['country_code'] ?? 'no') : 'null', "\n";
echo is_array($proc) ? ($proc['country_code'] ?? 'no') : 'null', "\n";
$utc = new DateTimeZone('UTC');
$u = $utc->getLocation();
echo is_array($u) ? ($u['country_code'] ?? 'no') : 'null', "\n";
--EXPECT--
DE
DE
??
