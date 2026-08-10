--TEST--
Language: exit(1.5) int status under PROFILE=8.4 (#29574, zend_execute.c)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsExitFunctionForm()) {
    echo "skip requires PHP_COMPILER_PROFILE=8.4 exit()/die() function form\n";
}
?>
--FILE--
<?php
// Covered by subprocess exit code in --EXPECT_EXIT--; stdout must stay empty.
// Deprecated text asserted in ExitStatusCoercionTest (#29574).
exit(1.5);
--EXPECT--
--EXPECT_EXIT--
1
