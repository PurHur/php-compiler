--TEST--
stdlib DOMDocument::saveXML(createDocumentFragment) matches xmlNodeDump (#32334)
--FILE--
<?php
$doc = new DOMDocument();
$f = $doc->createDocumentFragment();
echo $f->nodeName, '|', $doc->saveXML($f), "END\n";
--EXPECT--
#document-fragment|END
