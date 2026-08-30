--TEST--
AOT: SimpleXMLElement::addAttribute is visible in asXML (ext/simplexml/sxe.c)
--FILE--
<?php
$x = new SimpleXMLElement('<root><child/></root>');
$x->addAttribute('id', '42');
echo $x->asXML();
--EXPECT--
<?xml version="1.0"?>
<root id="42"><child/></root>
