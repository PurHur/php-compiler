--TEST--
stdlib substr_compare(null haystack/needle) soft-null DEP+coerce on 8.4 (#21515, reverts #20164, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
substr_compare_null_forward84.php
--EXPECT--
DEP
haystack OK
DEP
needle OK
DEP
offset OK
