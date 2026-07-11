--TEST--
stdlib DOMDocument(version, encoding) ctor — encoding in property and saveXML (#14497)
--FILE--
<?php
$doc = new DOMDocument('1.0', 'UTF-8');
echo $doc->encoding, "\n";
echo $doc->xmlVersion, "\n";
echo str_contains($doc->saveXML(), 'encoding="UTF-8"') ? 'yes' : 'no', "\n";
?>
--EXPECT--
UTF-8
1.0
yes
