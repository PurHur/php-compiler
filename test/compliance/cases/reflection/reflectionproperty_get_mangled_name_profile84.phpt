--TEST--
ReflectionProperty::getMangledName() absent on PROFILE=8.4 (#27592)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo method_exists(ReflectionProperty::class, 'getMangledName') ? "Y\n" : "missing\n";
--EXPECT--
missing
