--TEST--
Language: #[\Deprecated] on enum is a compile fatal under PROFILE=8.4 (#23701 / #6921, Zend/zend_attributes.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::advertisesDeprecatedAttributeClass()) {
    die('skip requires Deprecated builtin attribute (PROFILE=8.4)');
}
if (PHPCompiler\CompilerVersion::supportsDeprecatedTraitAttribute()) {
    die('skip 8.5+ uses validate_deprecated enum message');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
#[\Deprecated(message: 'Legacy enum', since: '8.4')]
enum Legacy { case A; }
echo "unreachable\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Attribute "Deprecated" cannot target class (allowed targets: function, method, class constant)
