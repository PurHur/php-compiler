--TEST--
AOT: assert() pass/fail via LLVM (issue #3157)
--FILE--
<?php
echo function_exists('assert') ? "1\n" : "0\n";
echo assert(true) ? "1\n" : "0\n";
@assert(false, 'boom');
echo assert(false) ? "1\n" : "0\n";
echo "ok\n";
--EXPECT--
1
1
0
ok
