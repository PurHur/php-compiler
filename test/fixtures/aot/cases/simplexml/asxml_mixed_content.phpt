--TEST--
SimpleXMLElement::asXML() mixed-content document dump under AOT (#31049)
--FILE--
<?php
$xml = simplexml_load_string('<r a="1"><c b="2">hi</c><d>x<e>y</e>z</d></r>');
echo trim($xml->asXML()), "\n";
?>
--EXPECT--
<?xml version="1.0"?>
<r a="1"><c b="2">hi</c><d>x<e>y</e>z</d></r>
