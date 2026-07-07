--TEST--
stdlib DOMNode::getRootNode() (#14449, ext/dom/node.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsDomNodeGetRootNode()) {
    die('skip DOMNode::getRootNode() not advertised on PHP 8.2 reference profile (#14599, ext/dom/node.c)');
}
?>
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><child><leaf/></child></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild->firstChild;
echo ($leaf->getRootNode() === $doc) ? "leaf_doc\n" : "leaf_other\n";
echo ($doc->getRootNode() === $doc) ? "doc_self\n" : "doc_other\n";
echo ($root->getRootNode() === $doc) ? "elem_doc\n" : "elem_other\n";
?>
--EXPECT--
leaf_doc
doc_self
elem_doc
