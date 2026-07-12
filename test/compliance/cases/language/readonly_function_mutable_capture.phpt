--TEST--
Language: readonly closure rejected — php-src parse error (#10012, was #7428)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsReadonlyFunction()) {
    die('skip readonly function enabled on PHP 8.4+ forward profile');
}
?>
--FILE--
<?php
$x = 1;
readonly function () use ($x) {};
--EXPECT_EXIT--
255
