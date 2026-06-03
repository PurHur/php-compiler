--TEST--
Language: unary + non-numeric string — JIT E_WARNING and int(0) (#4820)
--FILE--
<?php
@ini_set('display_errors', '1');
var_export(+'0x10');
echo "\n";
var_export(+'42');
echo "\n";
--EXPECT--
0
42
