--TEST--
Language: readonly function declaration rejected on reference profile (#10012, #17657)
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
readonly function f(): void {
    echo "ok\n";
}
f();
--EXPECT_EXIT--
255
