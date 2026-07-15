--TEST--
ext/dom DOMNode::compareDocumentPosition — not advertised on PHP 8.2 reference profile (#18092, ext/dom/node.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.2');
if (PHPCompiler\CompilerVersion::supportsDomNodeCompareDocumentPosition()) {
    die('skip PHP_COMPILER_PROFILE=8.2 unexpectedly enables compareDocumentPosition');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo method_exists(DOMNode::class, 'compareDocumentPosition') ? "fail\n" : "ok\n";
--EXPECT--
ok
