--TEST--
AOT: inline DOMNode::getRootNode() === $doc (#21832, ext/dom/node.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$child = $doc->createElement('child');
$leaf = $doc->createElement('leaf');
$doc->appendChild($root);
$root->appendChild($child);
$child->appendChild($leaf);
echo (int) $doc->isSameNode($leaf->getRootNode()), "\n";
echo (int) ($leaf->getRootNode() === $doc), "\n";
echo (int) ($doc === $leaf->getRootNode()), "\n";
--EXPECT--
1
1
1
