--TEST--
Language: FCC in file-scope const rejected under PROFILE=8.2 (#31167)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
const X = strlen(...);
echo (X)('ab'), "\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Constant expression contains invalid operations
