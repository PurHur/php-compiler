--TEST--
Language: readonly function declaration rejected — php-src parse error (#10012)
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
readonly function f(): int { return 1; }
echo f();
--EXPECT_EXIT--
255
