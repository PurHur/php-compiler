--TEST--
Stdlib: assert() — pass and fail with warning (VM, #3157)
--FILE--
<?php
ini_set('zend.assertions', '1');
ini_set('assert.exception', '0');
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
