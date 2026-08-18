--TEST--
stdlib DOMDocument::saveXML(createAttribute) matches xmlNodeDump (#32351)
--FILE--
<?php
$doc = new DOMDocument();
$attr = $doc->createAttribute('id');
echo $attr->nodeName, '|', $doc->saveXML($attr), "END\n";
--EXPECT--
id| id=""END
