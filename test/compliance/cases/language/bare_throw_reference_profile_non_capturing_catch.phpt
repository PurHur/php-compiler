--TEST--
Language: bare throw; + non-capturing catch rejected on reference profile (#15720, Zend/zend_language_parser.y)
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
declare(strict_types=1);

class Inner extends Exception {}

try {
    try {
        throw new Inner('rethrow');
    } catch (Inner) {
        throw;
    }
} catch (Inner $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT_EXIT--
255
