--TEST--
stdlib ini_set('memory_limit') unlimited -1 JIT/AOT (issue #5138)
--FILE--
<?php
$unlimited = '-'.'1';
$old = ini_set('memory_limit', $unlimited);
echo is_string($old) ? "set-ok\n" : "set-fail\n";
echo ini_get('memory_limit') === $unlimited ? "get-ok\n" : "get-fail\n";
--EXPECT--
set-ok
get-ok
