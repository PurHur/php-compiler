--TEST--
AOT: SimpleXMLElement dim write leftover of offsetGet (#35810 / ext/simplexml/sxe.c)
--FILE--
<?php
$x = new SimpleXMLElement('<root><child/></root>');
$x['id'] = '42';
echo $x->asXML();
echo $x['id'], "\n";
--EXPECT--
<?xml version="1.0"?>
<root id="42"><child/></root>
42
