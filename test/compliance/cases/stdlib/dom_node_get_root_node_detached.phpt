--TEST--
stdlib DOMNode::getRootNode() detached node returns self (#21766, ext/dom/node.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsDomNodeGetRootNode()) {
    die('skip DOMNode::getRootNode() not advertised on PHP 8.2 reference profile');
}
?>
--FILE--
<?php
$doc = new DOMDocument();
$detached = $doc->createElement('x');
echo ($detached->getRootNode() === $detached) ? "detached_self\n" : "detached_other\n";
echo ($detached->getRootNode() === $doc) ? "detached_doc_bad\n" : "detached_not_doc\n";
?>
--EXPECT--
detached_self
detached_not_doc
