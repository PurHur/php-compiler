--TEST--
stdlib DOMDocument::saveXML(createProcessingInstruction) matches xmlNodeDump (#32331)
--FILE--
<?php
$doc = new DOMDocument();
$pi = $doc->createProcessingInstruction('xml-stylesheet', 'type="text/xsl" href="s.xsl"');
echo $pi->nodeName, '|', $pi->nodeValue, '|', $pi->textContent, "\n";
echo $doc->saveXML($pi), "\n";
$empty = $doc->createProcessingInstruction('target');
echo $empty->nodeName, '|', $empty->nodeValue, "\n";
echo $doc->saveXML($empty), "\n";
--EXPECT--
xml-stylesheet|type="text/xsl" href="s.xsl"|type="text/xsl" href="s.xsl"
<?xml-stylesheet type="text/xsl" href="s.xsl"?>
target|
<?target?>
