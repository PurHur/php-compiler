--TEST--
Language: #[\Deprecated] on promoted ctor param cites parameter (#29420, Zend/zend_attributes.c)
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
class C {
    public function __construct(
        #[\Deprecated('old')]
        public $x = 1,
    ) {}
}
echo "unreachable\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Attribute "Deprecated" cannot target parameter (allowed targets: %s)
