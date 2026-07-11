--TEST--
stdlib var_export() hexdec/bindec overflow float ULP matches Zend (#14927)
--FILE--
<?php
var_export(hexdec('FFFFFFFFFFFFFFFF'));
echo "\n";
var_export(bindec(str_repeat('1', 65)));
echo "\n";
--EXPECT--
1.8446744073709552E+19
3.6893488147419103E+19
