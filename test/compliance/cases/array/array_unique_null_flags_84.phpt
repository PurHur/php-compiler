--TEST--
array_unique(null $flags) DEP+coerce on 8.4 (#21733, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
issue_21733_array_unique_null_flags.php
--EXPECT--
DEP
array_unique OK 2
default OK 2
