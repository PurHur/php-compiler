--TEST--
stdlib strftime(null)/gmstrftime(null) soft-null DEP+false on 8.4 (#21582, ext/standard/datetime.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
strftime_null_forward84.php
--EXPECT--
DEP_FN
DEP_NULL
strftime_null OK
DEP_FN
DEP_NULL
gmstrftime_null OK
DEP_FN
strftime_ok OK
