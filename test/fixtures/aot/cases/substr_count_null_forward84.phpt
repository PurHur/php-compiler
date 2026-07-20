--TEST--
AOT: substr_count(null) — soft-null on 8.4 forward profile (#21196)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo substr_count(null, 'a'), "\n";
--EXPECT--
0
