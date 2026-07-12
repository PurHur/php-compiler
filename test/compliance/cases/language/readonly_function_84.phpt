--TEST--
Language: readonly function keyword on PHP 8.4 forward profile (#17699, Zend/zend_compile.c)
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
declare(strict_types=1);

readonly function ro(): int {
    return 42;
}

echo ro(), "\n";
--EXPECT--
42
