--TEST--
dom DOMDocument::saveHTML() serializes CDATA as text (#24580)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><![CDATA[hello & <world>]]></root>');
echo 'html=', var_export($doc->saveHTML(), true), "\n";
$cd = $doc->documentElement->firstChild;
echo 'node=', var_export($doc->saveHTML($cd), true), "\n";
echo 'xml=', var_export($doc->saveXML(), true), "\n";
echo 'type=', $cd->nodeType, "\n";

$doc2 = new DOMDocument();
$root = $doc2->appendChild($doc2->createElement('root'));
$root->appendChild($doc2->createTextNode('before'));
$root->appendChild($doc2->createCDATASection('x & y'));
$root->appendChild($doc2->createCDATASection('<z>'));
$root->appendChild($doc2->createTextNode('after'));
echo 'mixed=', var_export($doc2->saveHTML(), true), "\n";
--EXPECT--
html='<root>hello & <world></root>
'
node='hello & <world>'
xml='<?xml version="1.0"?>
<root><![CDATA[hello & <world>]]></root>
'
type=4
mixed='<root>beforex & y<z>after</root>
'
