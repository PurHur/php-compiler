--TEST--
AOT: createDocumentFragment saveXML must not SIGSEGV (#32334)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$f = $doc->createDocumentFragment();
echo $f->nodeName, '|', $doc->saveXML($f), "END\n";
--EXPECT--
#document-fragment|END
