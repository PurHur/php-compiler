--TEST--
Language: exit(true)/die(true) int status under PROFILE=8.4 (#29573, zend_execute.c)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsExitFunctionForm()) {
    echo "skip requires PHP_COMPILER_PROFILE=8.4 exit()/die() function form\n";
}
?>
--FILE--
<?php
// Covered by subprocess exit code in --EXPECT_EXIT--; stdout must stay empty.
exit(true);
--EXPECT--
--EXPECT_EXIT--
1
