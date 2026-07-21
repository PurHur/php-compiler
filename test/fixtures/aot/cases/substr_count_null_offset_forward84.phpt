--TEST--
AOT: substr_count(null $offset) soft-null coerce on 8.4 (#21657, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// DEP is verified on VM/JIT; AOT checks coerce result (null offset → full count).
echo substr_count('aaa', 'a', null), "\n";
--EXPECT--
3
