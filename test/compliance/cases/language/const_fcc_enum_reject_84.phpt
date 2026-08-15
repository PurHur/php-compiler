--TEST--
Language: FCC in enum const rejected under PROFILE=8.4 (#31167)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
enum E { const X = strlen(...); }
echo 'ok';
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Constant expression contains invalid operations
