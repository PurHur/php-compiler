--TEST--
AOT: DateTimeZone::getName() / timezone_name_get() UTC (#27307)
--FILE--
<?php
$z = new DateTimeZone('UTC');
echo $z->getName(), "\n";
echo timezone_name_get($z), "\n";
--EXPECT--
UTC
UTC
