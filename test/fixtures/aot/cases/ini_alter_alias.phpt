--TEST--
AOT: ini_alter() alias registers and matches ini_set() (#6085)
--FILE--
<?php
$old = ini_alter('error_reporting', '0');
echo is_string($old) ? "1" : "0", "\n";
echo ini_alter('unknown_ini_key', 'x') === false ? "1" : "0", "\n";
--EXPECT--
1
1
