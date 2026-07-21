--TEST--
AOT: DOMNode::getRootNode() under PHP 8.4 forward profile (#21377, #21687, #21766, ext/dom/node.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$child = $doc->createElement('child');
$leaf = $doc->createElement('leaf');
// Detached nodes: root is self, not ownerDocument (#21766, php-src dom_get_root_node).
echo (int) $leaf->isSameNode($leaf->getRootNode()), "\n";
echo (int) $doc->isSameNode($leaf->getRootNode()), "\n";
// In-tree: walk parentNode to document (use isSameNode — === vs $doc mis-boxes in AOT #21687).
$doc->appendChild($root);
$root->appendChild($child);
$child->appendChild($leaf);
echo (int) $doc->isSameNode($leaf->getRootNode()), "\n";
echo (int) $doc->isSameNode($root->getRootNode()), "\n";
--EXPECT--
1
0
1
1
