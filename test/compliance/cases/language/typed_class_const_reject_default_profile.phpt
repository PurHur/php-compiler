--TEST--
Language: typed class constants rejected on default 8.4.0-dev profile (phpversion 8.2.31, #22705, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE');
if (PHPCompiler\CompilerVersion::supportsTypedClassConstants()) {
    die('skip default profile unexpectedly enables typed class constants (#22705)');
}
?>
--FILE--
<?php
echo 'ver=', phpversion(), "\n";
class C {
    public const string NAME = 'x';
}
echo C::NAME, "\n";
--EXPECT_EXIT--
255
