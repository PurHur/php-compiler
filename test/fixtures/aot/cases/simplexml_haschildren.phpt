--TEST--
AOT: SimpleXMLElement::hasChildren matches Zend on a fresh tree (ext/simplexml/sxe.c)
--FILE--
<?php
$x = new SimpleXMLElement('<root><a/></root>');
echo json_encode($x->hasChildren()), "\n";
$y = new SimpleXMLElement('<root/>');
echo json_encode($y->hasChildren()), "\n";
echo gettype($x->hasChildren()), "\n";
--EXPECT--
false
false
boolean
