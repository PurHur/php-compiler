--TEST--
Language: #[\Deprecated] rejects parameter under PROFILE=8.4 (#23701, Zend/zend_attributes.c)
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
function g(#[\Deprecated('arg')] $a) {
    return $a;
}
echo "unreachable\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Attribute "Deprecated" cannot target parameter (allowed targets: %s)
