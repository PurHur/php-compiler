--TEST--
stdlib substr_count(null $offset) soft-null DEP+coerce on 8.4 (#21657, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
substr_count_null_offset_forward84.php
--EXPECT--
DEP
offset OK
length_control OK
offset_one OK
