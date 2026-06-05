--TEST--
stdlib ini_alter() JIT/AOT path (#6085)
--FILE--
<?php
$old = ini_alter('display_errors', '0');
echo is_string($old) ? "ok\n" : "fail\n";
echo ini_alter('bogus_ini_key', '1') === false ? "false\n" : "bad\n";
--EXPECT--
ok
false
