--TEST--
stdlib intdiv(null) soft-null DEP+coerce on 8.4 JIT (#21593, ext/standard/math.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--RUNFILE--
intdiv_null_forward84.php
--EXPECT--
DEP
null_num1 OK
DEP
null_num2 OK
ok OK
non_numeric OK
