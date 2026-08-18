--TEST--
stdlib DOMDocument::saveXML(createEntityReference) matches xmlNodeDump (#32343)
--FILE--
<?php
$doc = new DOMDocument();
$ref = $doc->createEntityReference('amp');
echo $ref->nodeName, '|', $doc->saveXML($ref), "END\n";
--EXPECT--
amp|&amp;END
