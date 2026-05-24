--TEST--
stdlib ini_set() JIT/AOT path (issue #1374)
--FILE--
<?php
$old = ini_set('display_errors', '0');
echo is_string($old) ? "ok\n" : "fail\n";
echo ini_set('bogus_ini_key', '1') === false ? "false\n" : "bad\n";
--EXPECT--
ok
false
