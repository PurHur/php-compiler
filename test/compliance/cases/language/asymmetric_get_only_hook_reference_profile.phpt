--TEST--
Language: public (private(set)) get-only hook — Zend diagnostic on reference profile (#16452, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip property hooks enabled on PHP 8.4.0+ target');
}
?>
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
