--TEST--
stdlib DOMNode::hasChildNodes() (#14418, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a/></root>');
$root = $doc->documentElement;
$leaf = $doc->createElement('leaf');
echo (int) $root->hasChildNodes(), "\n";
echo (int) $leaf->hasChildNodes(), "\n";
?>
--EXPECT--
1
0
