--TEST--
Language: dynamic class constant fetch C::{$name} rejected on unset PROFILE (#31182, Zend/zend_language_parser.y)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsDynamicClassConstFetch()) {
    die('skip dynamic class const fetch enabled on 8.3+ forward / stable profile');
}
?>
--FILE--
<?php
class C {
    public const FOO = 'bar';
}
$name = 'FOO';
echo C::{$name}, "\n";
--EXPECT_EXIT--
255
