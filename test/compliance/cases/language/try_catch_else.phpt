--TEST--
Language: try/catch/else runs when no exception (#15817, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsTryCatchElse()) {
    die('skip try/catch/else requires PHP_COMPILER_PROFILE=8.4');
}
?>
--FILE--
<?php
try {
    echo "try\n";
} catch (Throwable) {
    echo "catch\n";
} else {
    echo "else\n";
}
--EXPECT--
try
else

--FILE--
<?php
try {
    throw new Exception('x');
} catch (Throwable) {
    echo "catch\n";
} else {
    echo "else\n";
}
--EXPECT--
catch
