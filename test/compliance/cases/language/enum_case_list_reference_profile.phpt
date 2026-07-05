--TEST--
Language: comma-separated enum case list rejected on reference profile (#16665, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsEnumCaseList()) {
    die('skip enum case list syntax enabled on PHP 8.5+ forward profile');
}
?>
--FILE--
<?php
enum E {
    case A, B, C;
}
echo E::A->name, E::C->name, "\n";
--EXPECT_EXIT--
255
