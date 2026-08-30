--TEST--
AOT: SimpleXMLElement::offsetSet/offsetExists method leftover of dim write (#35810 / sxe.c)
--FILE--
<?php
$x = new SimpleXMLElement('<root id="42"><child/></root>');
$x->offsetSet('k', 'v');
echo $x->asXML();
echo $x->offsetExists('k') ? "oe_k=1\n" : "oe_k=0\n";
echo $x->offsetExists('id') ? "oe_id=1\n" : "oe_id=0\n";
--EXPECT--
<?xml version="1.0"?>
<root id="42" k="v"><child/></root>
oe_k=1
oe_id=1
