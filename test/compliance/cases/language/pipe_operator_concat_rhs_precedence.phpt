--TEST--
Language: pipe |> loses to concat on RHS — Zend Closure→string Error (#28438, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsPipeOperator()) {
    die('skip requires PHP 8.5+ pipe operator forward profile');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
echo ("a" |> strtoupper(...)) . "x", "\n";
try {
    echo "a" |> strtoupper(...) . "x", "\n";
    echo "UNEXPECTED_OK\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Ax
Object of class Closure could not be converted to string
