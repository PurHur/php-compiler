--TEST--
AOT: ReflectionClass::getShortName() (#15274)
--FILE--
<?php
echo (new ReflectionClass(stdClass::class))->getShortName(), "\n";
--EXPECT--
stdClass
