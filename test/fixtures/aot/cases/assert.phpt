--TEST--
AOT: assert() pass/fail via LLVM (issue #3157)
--FILE--
<?php
ini_set('assert.exception', '0');
echo function_exists('assert') ? "1\n" : "0\n";
echo assert(true) ? "1\n" : "0\n";
@assert(false, 'boom');
echo assert(false) ? "1\n" : "0\n";
echo "ok\n";
--EXPECT--
1
1
1
ok
