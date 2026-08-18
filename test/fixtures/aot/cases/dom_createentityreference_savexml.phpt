--TEST--
AOT: createEntityReference saveXML must not SIGSEGV (#32343)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$ref = $doc->createEntityReference('amp');
echo $ref->nodeName, '|', $doc->saveXML($ref), "END\n";
--EXPECT--
amp|&amp;END
