--TEST--
DateTimeZone::getTransitions() — spring DST boundary (#16291, php-src date_timezone_transitions)
--FILE--
<?php
declare(strict_types=1);
$tz = new DateTimeZone('America/New_York');
$trans = $tz->getTransitions(strtotime('2020-03-01'), strtotime('2020-03-15'));
echo count($trans), "\n";
echo ($trans[1]['isdst'] ?? false) ? 'spring_isdst' : 'no_spring_isdst', "\n";
?>
--EXPECT--
2
spring_isdst
