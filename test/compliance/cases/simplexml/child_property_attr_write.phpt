--TEST--
SimpleXML: $sxe->child["attr"] / $sxe->child[0]["attr"] write (#20005, sxe_prop_dim_write)
--FILE--
<?php
$xml = simplexml_load_string('<root><item>1</item></root>');
$xml->item['id'] = 'x';
echo (string) $xml->item['id'], "\n";
echo trim($xml->asXML()), "\n";

$xml2 = simplexml_load_string('<root><item>1</item></root>');
$xml2->item[0]['id'] = 'y';
echo (string) $xml2->item['id'], "\n";
echo trim($xml2->asXML()), "\n";

$xml3 = simplexml_load_string('<root><item>1</item></root>');
$child = $xml3->item;
$child['id'] = 'c';
echo trim($xml3->asXML()), "\n";

$x = new SimpleXMLElement('<root/>');
$c = $x->addChild('item', 'hi');
$c['id'] = '1';
echo trim($x->asXML()), "\n";
--EXPECT--
x
<?xml version="1.0"?>
<root><item id="x">1</item></root>
y
<?xml version="1.0"?>
<root><item id="y">1</item></root>
<?xml version="1.0"?>
<root><item id="c">1</item></root>
<?xml version="1.0"?>
<root><item id="1">hi</item></root>
