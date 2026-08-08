--TEST--
stdlib class_alias() allows internal class originals (#29084, re-#9211/#18290, Zend/zend_builtin_functions.c)
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
var_export(class_alias('stdClass', 'SC9211'));
echo "\n";
var_export((new SC9211()) instanceof stdClass);
echo "\n";
var_export(class_alias('Exception', 'E29084'));
echo "\n";
var_export((new E29084('x')) instanceof Exception);
echo "\n";
--EXPECT--
true
true
true
true
