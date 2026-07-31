--TEST--
stdlib DOMNode::compareDocumentPosition() document order (#14448, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><parent><child/></parent><sibling/></root>');
$parent = $doc->getElementsByTagName('parent')->item(0);
$child = $doc->getElementsByTagName('child')->item(0);
$sibling = $doc->getElementsByTagName('sibling')->item(0);
echo $parent->compareDocumentPosition($child), "\n";
echo $child->compareDocumentPosition($parent), "\n";
echo $parent->compareDocumentPosition($sibling), "\n";
echo (int) (($parent->compareDocumentPosition($sibling) & DOMNode::DOCUMENT_POSITION_DISCONNECTED) === 0), "\n";
--EXPECT--
20
10
4
1
