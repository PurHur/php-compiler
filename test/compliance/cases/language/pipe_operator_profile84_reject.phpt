--TEST--
Language: pipe operator |> rejected on PROFILE=8.4 (#22792, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
// Gate uses languageProfileVersion(); this case forces PROFILE=8.4 via --ENV--.
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo "hello" |> strtoupper(...);
--EXPECT_EXIT--
255
