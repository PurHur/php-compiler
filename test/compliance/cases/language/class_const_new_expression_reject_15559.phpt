--TEST--
Language: class constant `new` expression must compile-error (#15559, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsClassConstObjectExpressions()) {
    die('skip class const object expressions enabled on stable 8.4+ forward profile');
}
?>
--FILE--
<?php

declare(strict_types=1);

class Holder {
    public const OBJ = new \stdClass();
}

echo get_class(Holder::OBJ), "\n";
--EXPECT_EXIT--
255
