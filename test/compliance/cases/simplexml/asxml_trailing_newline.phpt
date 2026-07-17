--TEST--
SimpleXMLElement::asXML() document serialization ends with newline (#19934, ext/simplexml/sxe.c)
--FILE--
<?php
$s = simplexml_load_string('<r><a>1</a><b>2</b></r>');
unset($s->a);
echo $s->asXML();
$s2 = simplexml_load_string('<r><a>1</a></r>');
echo $s2->asXML();
?>
--EXPECT--
<?xml version="1.0"?>
<r><b>2</b></r>
<?xml version="1.0"?>
<r><a>1</a></r>
