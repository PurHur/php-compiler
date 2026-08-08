--TEST--
Language: #[\Deprecated] on enum is a compile fatal on PHP 8.5+ (#26307 / #28892, Zend/zend_attributes.c validate_deprecated)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsDeprecatedTraitAttribute()) {
    die('skip requires PHP 8.5+ deprecated trait attribute validator');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
#[\Deprecated('old')]
enum E {}
echo "unreachable\n";
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Cannot apply #[\Deprecated] to enum E
