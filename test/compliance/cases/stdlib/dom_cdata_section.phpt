--TEST--
DOMCDATASection + createCDATASection + loadXML CDATA round-trip (#17526)
--FILE--
<?php
echo class_exists('DOMCDATASection', false) ? '1' : '0', "\n";
$doc = new DOMDocument();
$section = $doc->createCDATASection('hi');
echo $section::class, "\n";
echo $section->data, "\n";
$doc->loadXML('<a><![CDATA[x]]></a>');
$child = $doc->documentElement->firstChild;
echo $child::class, "\n";
echo $child->data, "\n";
echo $doc->saveXML($child), "\n";
--EXPECT--
1
DOMCdataSection
hi
DOMCdataSection
x
<![CDATA[x]]>
