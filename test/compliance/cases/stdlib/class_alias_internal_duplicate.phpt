--TEST--
stdlib class_alias() duplicate internal name warns + false (#29084, re-#18290, Zend/zend_builtin_functions.c)
--FILE--
<?php
var_export(class_alias('stdClass', 'stdClass'));
echo "\n";
--EXPECT--
PHP Warning:  Cannot declare class stdClass, because the name is already in use
false
