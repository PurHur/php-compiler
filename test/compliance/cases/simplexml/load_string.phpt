--TEST--
SimpleXML: simplexml_load_string child and attribute access (#3338, ext/simplexml/simplexml.c)
--FILE--
<?php
$x = simplexml_load_string('<root><item id="1">a</item><item id="2">b</item></root>');
echo (string) $x->item[0], "\n";
echo (string) $x->item[1], "\n";
echo (string) $x->item[0]['id'], "\n";
--EXPECT--
a
b
1
