--TEST--
AOT: DateTimeZone::getName() / timezone_name_get() UTC (#27307, #29733)
--FILE--
<?php
$z = new DateTimeZone('UTC');
echo $z->getName(), "\n";
echo timezone_name_get($z), "\n";
$ny = new DateTimeZone('America/New_York');
echo $ny->getName(), "\n";
--EXPECT--
UTC
UTC
America/New_York
