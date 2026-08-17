--TEST--
AOT: DOMNode::contains() descendant check under PHP 8.4 forward profile (#21377, #21687, ext/dom/node.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$parent = $doc->createElement('parent');
$child = $doc->createElement('child');
$sibling = $doc->createElement('sibling');
$doc->appendChild($root);
$root->appendChild($parent);
$root->appendChild($sibling);
$parent->appendChild($child);
echo (int) $root->contains($child), "\n";
echo (int) $parent->contains($child), "\n";
echo (int) $child->contains($root), "\n";
echo (int) $root->contains($sibling), "\n";
echo (int) $root->contains($root), "\n";
echo (int) $root->contains(null), "\n";
$n = null;
echo (int) $root->contains($n), "\n";
--EXPECT--
1
1
0
1
1
0
0
