--TEST--
Language: file-scope typed constants (issue #7081, Zend/zend_compile.c PHP 8.3+)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsGlobalTypedConstants()) {
    die('skip global typed constants require PHP 8.3+ target');
}
?>
--FILE--
<?php
const string GLOBAL_NAME = 'alpha';
const array GLOBAL_LIST = [1, 2, 3];

echo GLOBAL_NAME, "\n";
echo GLOBAL_LIST[0], "\n";
--EXPECT--
alpha
1
