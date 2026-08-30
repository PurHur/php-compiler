--TEST--
AOT: nested SimpleXMLElement property write leftover of #35820 (#35834)
--FILE--
<?php
$x = new SimpleXMLElement('<root><a><b>old</b></a></root>');
$x->a->b = 'new';
echo $x->asXML();
echo (string) $x->a->b;
--EXPECT--
<?xml version="1.0"?>
<root><a><b>new</b></a></root>
new
