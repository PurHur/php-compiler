--TEST--
Language: public (private(set)) get-only hook — Zend diagnostic on reference profile (#16452, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.2');
if (PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip PHP_COMPILER_PROFILE=8.2 unexpectedly enables property hooks');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
declare(strict_types=1);

class C {
    public (private(set)) string $x {
        get => 'hi';
    }
}
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: syntax error, unexpected token "private" in %s on line %d
