--TEST--
AOT unserialize(serialize(DateInterval/DateTimeZone)) restores Zend wire (#34599)
--FILE--
<?php
declare(strict_types=1);
$i = new DateInterval('P1Y2M3DT4H5M6S');
$s = serialize($i);
$u = unserialize($s);
echo $u->format('%Y-%M-%D %H:%I:%S'), PHP_EOL;
$z = new DateTimeZone('Europe/Berlin');
$sz = serialize($z);
$uz = unserialize($sz);
echo $uz->getName(), PHP_EOL;
echo unserialize(serialize(new DateInterval('PT2H30M')))->format('%H:%I'), PHP_EOL;
echo unserialize(serialize(new DateTimeZone('UTC')))->getName(), PHP_EOL;
--EXPECT--
01-02-03 04:05:06
Europe/Berlin
02:30
UTC
