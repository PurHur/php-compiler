--TEST--
AOT timezone_location_get() (#6041 phase 2)
--FILE--
<?php
$tz = new DateTimeZone('Europe/Berlin');
$loc = timezone_location_get($tz);
echo is_array($loc) ? ($loc['country_code'] ?? '?') : '?', "\n";
--EXPECT--
DE
