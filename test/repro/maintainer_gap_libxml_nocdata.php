<?php
// Zend parity: DOMDocument::loadXML(..., LIBXML_NOCDATA) merges CDATA into text (ext/dom).
$xml = '<r><![CDATA[hello]]></r>';
$dom = new DOMDocument();
$dom->loadXML($xml, LIBXML_NOCDATA);
$ch = $dom->documentElement->firstChild;
echo 'nocdata=', $ch->nodeName, ' value=', $ch->nodeValue, "\n";
$dom2 = new DOMDocument();
$dom2->loadXML($xml);
$ch2 = $dom2->documentElement->firstChild;
echo 'default=', $ch2->nodeName, "\n";
