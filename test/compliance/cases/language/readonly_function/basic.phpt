--TEST--
Language: readonly function declaration on PHP 8.4 forward profile (#17657, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsReadonlyFunction()) {
    die('skip readonly function requires PHP_COMPILER_PROFILE=8.4');
}
?>
--FILE--
<?php
readonly function ro(): int {
    return 42;
}
echo ro(), "\n";
--EXPECT--
42
