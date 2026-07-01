--TEST--
stdlib DOMNode::getNodePath() (#14410, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><child><leaf/></child></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild->firstChild;
echo $doc->getNodePath(), "\n";
echo $root->getNodePath(), "\n";
echo $leaf->getNodePath(), "\n";
?>
--EXPECT--
/
/root
/root/child/leaf
