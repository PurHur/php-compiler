--TEST--
ReflectionClass::getLazyPropertyNames() phantom vs php-src (#28516, re-#6606)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'getLazyPropertyNames=', method_exists(ReflectionClass::class, 'getLazyPropertyNames') ? '1' : '0', "\n";
echo 'newLazyGhost=', method_exists(ReflectionClass::class, 'newLazyGhost') ? '1' : '0', "\n";
--EXPECT--
getLazyPropertyNames=0
newLazyGhost=1
