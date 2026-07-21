--TEST--
AOT: substr_compare(null) soft-null coerce on 8.4 (#21515, reverts #20164, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// DEP is verified on VM/JIT; AOT checks coerce result (empty haystack vs 'a' → -1).
echo substr_compare(null, 'a', 0), "\n";
--EXPECT--
-1
