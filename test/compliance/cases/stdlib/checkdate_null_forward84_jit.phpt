--TEST--
JIT: checkdate(null) soft-null DEP+coerce on 8.4 (#21594, ext/standard/datetime.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--RUNFILE--
checkdate_null_forward84.php
--EXPECT--
DEP
null_month OK
DEP
null_day OK
leap_ok OK
non_numeric OK
