--TEST--
Language: `new` without `()` in class initializers must compile-error (#6549, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsNewWithoutParensInConstAndStaticInitializers()) {
    die('skip bare new allowed on PHP 8.4 forward profile');
}
?>
--FILE--
<?php
class ConstBad {
    const X = new stdClass;
}
--EXPECT_EXIT--
255
