--TEST--
AOT: createProcessingInstruction saveXML must not SIGSEGV (#32331)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$pi = $doc->createProcessingInstruction('xml-stylesheet', 'type="text/xsl" href="s.xsl"');
echo $pi->nodeName, '|', $pi->nodeValue, '|', $pi->textContent, "\n";
echo $doc->saveXML($pi), "\n";
$pi2 = $doc->createProcessingInstruction('target');
echo $pi2->nodeName, '|', $pi2->nodeValue, "\n";
echo $doc->saveXML($pi2), "\n";
--EXPECT--
xml-stylesheet|type="text/xsl" href="s.xsl"|type="text/xsl" href="s.xsl"
<?xml-stylesheet type="text/xsl" href="s.xsl"?>
target|
<?target?>
