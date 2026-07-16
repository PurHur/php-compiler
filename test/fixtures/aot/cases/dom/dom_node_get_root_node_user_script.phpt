--TEST--
AOT: DOMNode::getRootNode() living API under PHP 8.4 forward profile (#19507, ext/dom/node.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><child><leaf/></child></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild->firstChild;
echo ($leaf->getRootNode() === $doc) ? "leaf_doc\n" : "leaf_other\n";
echo ($doc->getRootNode() === $doc) ? "doc_self\n" : "doc_other\n";
echo ($root->getRootNode() === $doc) ? "elem_doc\n" : "elem_other\n";
--EXPECT--
leaf_doc
doc_self
elem_doc
