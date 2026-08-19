--TEST--
stdlib DOMNode::getNodePath matches xmlGetNodePath (#32474, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><child><leaf/></child></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild->firstChild;
echo $doc->getNodePath(), '|', $root->getNodePath(), '|', $leaf->getNodePath(), "\n";
$dup = new DOMDocument();
$dup->loadXML('<root><child/><child/><child/></root>');
$droot = $dup->documentElement;
echo $droot->firstChild->getNodePath(), '|', $droot->lastChild->getNodePath(), "\n";
--EXPECT--
/|/root|/root/child/leaf
/root/child[1]|/root/child[3]
