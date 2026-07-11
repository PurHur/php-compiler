--TEST--
stdlib class_alias() JIT failure paths emit E_WARNING (#13353)
--FILE--
<?php
class Target13353Jit {}
var_export(class_alias('NoSuchClassJit', 'AliasMissing13353Jit'));
echo "\n";
var_export(class_alias('Target13353Jit', 'AliasDup13353Jit'));
echo "\n";
var_export(class_alias('Target13353Jit', 'AliasDup13353Jit'));
echo "\n";
--EXPECT--
PHP Warning:  Class "NoSuchClassJit" not found
PHP Warning:  Cannot declare class AliasDup13353Jit, because the name is already in use
false
true
false
