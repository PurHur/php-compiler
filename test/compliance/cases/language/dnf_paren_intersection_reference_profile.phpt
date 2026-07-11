--TEST--
Language: parenthesized DNF intersection-only types rejected on reference profile (#14904, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsParenthesizedDnfIntersectionTypes()) {
    die('skip parenthesized DNF intersection types enabled on PHP 8.4.0+ target');
}
?>
--FILE--
<?php
interface I1 {}
interface I2 {}
function accepts((I1&I2) $o): string { return 'ok'; }
echo "run\n";
--EXPECT_EXIT--
255
