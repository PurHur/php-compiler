--TEST--
AOT: SimpleXMLElement property write leftover of isset/unset (#35823 / #35814 / ext/simplexml/sxe.c)
--FILE--
<?php
$x = new SimpleXMLElement('<root id="42"><child>a</child></root>');
$x->child = 'hello';
echo $x->asXML();
echo $x->child, "\n";
--EXPECT--
<?xml version="1.0"?>
<root id="42"><child>hello</child></root>
hello
