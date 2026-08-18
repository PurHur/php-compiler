--TEST--
AOT: createCDATASection saveXML must not SIGSEGV (#32327)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$c = $doc->createCDATASection('hi');
echo $c->nodeName, '|', $c->nodeValue, '|', $c->textContent, "\n";
echo $doc->saveXML($c), "\n";
--EXPECT--
#cdata-section|hi|hi
<![CDATA[hi]]>
