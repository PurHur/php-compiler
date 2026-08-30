--TEST--
AOT: SimpleXMLElement property isset/unset leftover of __get (#35814)
--FILE--
<?php
$x = new SimpleXMLElement('<root a="1"><child>t</child></root>');
echo isset($x->child) ? "isset_child=1\n" : "isset_child=0\n";
echo isset($x->missing) ? "isset_missing=1\n" : "isset_missing=0\n";
unset($x->child);
echo $x->asXML();
?>
--EXPECT--
isset_child=1
isset_missing=0
<?xml version="1.0"?>
<root a="1"/>
