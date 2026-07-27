--TEST--
Language: #[\Deprecated] rejects property under PROFILE=8.4 (#23701, Zend/zend_attributes.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::advertisesDeprecatedAttributeClass()) {
    die('skip requires Deprecated builtin attribute (PROFILE=8.4)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class P {
    #[\Deprecated('p')]
    public $x = 1;
}
echo "unreachable\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Attribute "Deprecated" cannot target property (allowed targets: %s)
