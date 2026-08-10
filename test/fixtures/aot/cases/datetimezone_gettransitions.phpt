--TEST--
AOT: DateTimeZone::getTransitions(0, 86400) UTC (#26799, #29734)
--FILE--
<?php
$tz = new DateTimeZone('UTC');
$t = $tz->getTransitions(0, 86400);
echo 'ok:', (is_array($t) && count($t) >= 1) ? '1' : '0', "\n";
$b = 0;
$e = 86400;
$t2 = $tz->getTransitions($b, $e);
echo 'var:', (is_array($t2) && count($t2) >= 1) ? '1' : '0', "\n";
--EXPECT--
ok:1
var:1
