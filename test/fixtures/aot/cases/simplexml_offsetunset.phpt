--TEST--
AOT: SimpleXMLElement dim delete leftover of offsetSet (#35817 / #35810 / ext/simplexml/sxe.c)
--FILE--
<?php
$x = new SimpleXMLElement('<root id="42"><child/></root>');
unset($x['id']);
echo $x->asXML();
echo isset($x['id']) ? "set\n" : "unset\n";
--EXPECT--
<?xml version="1.0"?>
<root><child/></root>
unset
