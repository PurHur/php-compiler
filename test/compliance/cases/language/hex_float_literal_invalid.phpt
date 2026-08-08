--TEST--
Language: PHP 8.4 hex float invalid suffix compile error (#7041, #29061)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (!PHPCompiler\CompilerVersion::supportsHexFloatLiterals()) {
    die('skip requires PHP_COMPILER_PROFILE=8.4 hex-float gate (#29061)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 0x1.8q+1;
--EXPECT_EXIT--
255
