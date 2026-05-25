--TEST--
stdlib ini_get() JIT/AOT path (issue #1374, #1492)
--FILE--
<?php
ini_set('display_errors', '0');
echo ini_get('display_errors') === '0' ? "ok\n" : "fail\n";
echo ini_get('bogus_ini_key') === false ? "false\n" : "bad\n";
--EXPECT--
ok
false
