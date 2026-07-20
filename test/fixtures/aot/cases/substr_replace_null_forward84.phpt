--TEST--
AOT: substr_replace(null) — soft-null on 8.4 forward profile (#21196)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo substr_replace(null, 'x', 0), "\n";
--EXPECT--
x
