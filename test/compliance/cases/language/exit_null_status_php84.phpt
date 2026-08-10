--TEST--
Language: exit(null) status 0 under PROFILE=8.4 (#29575, zend_execute.c)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsExitFunctionForm()) {
    echo "skip requires PHP_COMPILER_PROFILE=8.4 exit()/die() function form\n";
}
?>
--FILE--
<?php
// Covered by subprocess exit code in --EXPECT_EXIT--; stdout must stay empty.
// Deprecated text asserted in ExitStatusCoercionTest (#29575).
exit(null);
--EXPECT--
--EXPECT_EXIT--
0
