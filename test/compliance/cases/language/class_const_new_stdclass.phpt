--TEST--
Language: class constant `new stdClass()` — shared identity (#15583, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsClassConstObjectExpressions()) {
    die('skip class const object expressions disabled on reference profile');
}
?>
--FILE--
<?php

declare(strict_types=1);

class Holder {
    public const OBJ = new \stdClass();
}

echo get_class(Holder::OBJ), "\n";
echo Holder::OBJ === Holder::OBJ ? "1\n" : "0\n";
--EXPECT--
stdClass
1
