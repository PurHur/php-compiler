--TEST--
stdlib class_alias() duplicate internal name warns + false (#29084, re-#18290, Zend/zend_builtin_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::allowsClassAliasOfInternalClass()) {
    die('skip internal class_alias requires PHP 8.3+ profile');
}
?>
--FILE--
<?php
var_export(class_alias('stdClass', 'stdClass'));
echo "\n";
--EXPECT--
PHP Warning:  Cannot declare class stdClass, because the name is already in use
false
