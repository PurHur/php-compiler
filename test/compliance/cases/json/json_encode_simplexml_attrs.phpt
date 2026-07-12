--TEST--
json_encode() SimpleXMLElement — @attributes wire (#18291, ext/simplexml/simplexml.c)
--FILE--
<?php
$xml = simplexml_load_string('<root id="1"><item>a</item></root>');
echo json_encode($xml), "\n";
--EXPECT--
{"@attributes":{"id":"1"},"item":"a"}
