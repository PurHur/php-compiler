--TEST--
AOT: DOMNode::getRootNode() under PHP 8.4 forward profile (#21377, #21687, ext/dom/node.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$child = $doc->createElement('child');
$leaf = $doc->createElement('leaf');
// Assign first: inline MethodCall temps mis-type === against $doc in AOT (#21687).
// getRootNode returns ownerDocument (stored by createElement).
$a = $leaf->getRootNode();
$b = $root->getRootNode();
$c = $child->getRootNode();
echo ($a === $doc) ? "leaf_doc\n" : "leaf_other\n";
echo ($b === $doc) ? "elem_doc\n" : "elem_other\n";
echo ($c === $doc) ? "child_doc\n" : "child_other\n";
--EXPECT--
leaf_doc
elem_doc
child_doc
