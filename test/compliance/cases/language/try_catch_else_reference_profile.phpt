--TEST--
Language: try/catch/else rejected on reference profile (#15817)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsTryCatchElse()) {
    die('skip try/catch/else enabled on PHP 8.4.0+ target');
}
?>
--FILE--
<?php
try {
    echo "try\n";
} catch (Throwable) {
} else {
    echo "else\n";
}
--EXPECT_EXIT--
255
