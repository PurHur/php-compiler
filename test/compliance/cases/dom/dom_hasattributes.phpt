--TEST--
stdlib DOMNode::hasAttributes matches xmlNode->properties (#32458, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root id="x"><child/></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild;
echo (int) $root->hasAttributes(), '|', (int) $leaf->hasAttributes(), "\n";
--EXPECT--
1|0
