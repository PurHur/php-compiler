--TEST--
highlight_string(null) DEP+coerce on 8.4 (#21504, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
issue_21504_highlight_string_null.php
--EXPECT--
DEP
highlight_string OK len=51
