--TEST--
AOT: createProcessingInstruction saveXML must not SIGSEGV (#32331)
--FILE--
<?php
declare(strict_types=1);
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
