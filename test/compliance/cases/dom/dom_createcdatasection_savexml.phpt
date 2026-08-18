--TEST--
stdlib DOMDocument::saveXML(createCDATASection) matches xmlNodeDump (#32327)
--FILE--
<?php
$doc = new DOMDocument();
$c = $doc->createCDATASection('hi');
echo $c->nodeName, '|', $c->nodeValue, '|', $c->textContent, "\n";
echo $doc->saveXML($c), "\n";
--EXPECT--
#cdata-section|hi|hi
<![CDATA[hi]]>
