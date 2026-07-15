--TEST--
Language: __PROPERTY__ outside property hook must compile-error (#18815, Zend/zend_compile.c)
--SKIPIF--
<?php
die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI');
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo __PROPERTY__, "\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Cannot use __PROPERTY__ outside of a property hook
