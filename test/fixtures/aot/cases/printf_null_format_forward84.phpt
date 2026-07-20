--TEST--
AOT: printf(null) soft-null on 8.4 forward profile (#21234, reverts #20197)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$f = null;
$n = printf($f);
echo (0 === $n) ? "OK\n" : "BAD\n";
--EXPECT--
OK
