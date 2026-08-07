--TEST--
Stdlib: ReflectionClass::getLazyInitializationException() phantom vs php-src (#28516, re-#6514)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'getLazyInitializationException=', method_exists(ReflectionClass::class, 'getLazyInitializationException') ? '1' : '0', "\n";
echo 'getLazyInitializer=', method_exists(ReflectionClass::class, 'getLazyInitializer') ? '1' : '0', "\n";
--EXPECT--
getLazyInitializationException=0
getLazyInitializer=1
