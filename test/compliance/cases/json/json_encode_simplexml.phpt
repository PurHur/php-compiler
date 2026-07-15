--TEST--
json_encode() SimpleXMLElement — repeated child tags as array (#18291, ext/simplexml/simplexml.c)
--FILE--
<?php
$xml = simplexml_load_string('<root><item>a</item><item>b</item></root>');
echo json_encode($xml), "\n";
--EXPECT--
{"item":["a","b"]}
