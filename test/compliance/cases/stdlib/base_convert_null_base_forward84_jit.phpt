--TEST--
stdlib base_convert() null bases — DEP+ValueError on 8.4 JIT (#21704, ext/standard/base_convert.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--RUNFILE--
base_convert_null_base_forward84.php
--EXPECT--
DEP
null_from_base OK
DEP
null_to_base OK
ok OK
