--TEST--
Language: bare throw; rejected on Zend 8.2 reference profile (#14239, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsBareRethrow()) {
    die('skip bare rethrow enabled on PHP 8.4.0+ target');
}
?>
--FILE--
<?php
class Ex extends Exception {}

try {
    try {
        throw new Ex();
    } catch (Ex $e) {
        throw;
    }
} catch (Ex $e) {
    echo "ok\n";
}
--EXPECT_EXIT--
255
