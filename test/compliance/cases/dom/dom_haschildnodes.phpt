--TEST--
stdlib DOMNode::hasChildNodes matches xmlNode->children (#32427, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><child/></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild;
echo (int) $root->hasChildNodes(), '|', (int) $leaf->hasChildNodes(), "\n";
--EXPECT--
1|0
