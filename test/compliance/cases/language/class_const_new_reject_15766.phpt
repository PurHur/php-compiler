--TEST--
Language: class constant `new stdClass()` rejected on 8.4 forward profile (#15766, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
$profile = getenv('PHP_COMPILER_PROFILE');
if (!is_string($profile) || '8.4' !== trim($profile)) {
    die('skip requires PHP_COMPILER_PROFILE=8.4');
}
if (PHPCompiler\CompilerVersion::supportsClassConstObjectExpressions()) {
    die('skip class const object expressions enabled on 8.4 forward profile');
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
