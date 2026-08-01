--TEST--
Language: Closures / FCC in const exprs rejected under PROFILE≤8.4 (#26240)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
const C = static fn(int $x): int => $x + 1;
echo (C)(2), "\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Constant expression contains invalid operations
