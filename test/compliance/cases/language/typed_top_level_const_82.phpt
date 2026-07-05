--TEST--
Language: top-level typed constants rejected on reference profile (#16651, Zend/zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsGlobalTypedConstants()) {
    die('skip global typed constants enabled on forward profile');
}
?>
--FILE--
<?php
const int X = 7;
echo X, "\n";
--EXPECT_EXIT--
255
