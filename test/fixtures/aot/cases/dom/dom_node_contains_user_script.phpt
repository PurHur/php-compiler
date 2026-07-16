--TEST--
AOT: DOMNode::contains() living API under PHP 8.4 forward profile (#19507, ext/dom/node.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><parent><child/></parent><sibling/></root>');
$root = $doc->documentElement;
$parent = $root->firstChild;
$child = $parent->firstChild;
$sibling = $root->lastChild;
echo (int) $root->contains($child), "\n";
echo (int) $parent->contains($child), "\n";
echo (int) $child->contains($root), "\n";
echo (int) $root->contains($sibling), "\n";
echo (int) $root->contains($root), "\n";
echo (int) $root->contains(null), "\n";
--EXPECT--
1
1
0
1
1
0
