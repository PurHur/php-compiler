--TEST--
AOT: DOMNode::compareDocumentPosition ancestor/descendant flags (#25878, ext/dom/node.c)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$root = $doc->createElement('root');
$parent = $doc->createElement('parent');
$child = $doc->createElement('child');
$sibling = $doc->createElement('sibling');
$doc->appendChild($root);
$root->append($parent, $sibling);
$parent->appendChild($child);
echo $parent->compareDocumentPosition($child), "\n";
echo $child->compareDocumentPosition($parent), "\n";
echo $parent->compareDocumentPosition($sibling), "\n";
echo (int) $parent->contains($child), "\n";
--EXPECT--
20
10
4
1
