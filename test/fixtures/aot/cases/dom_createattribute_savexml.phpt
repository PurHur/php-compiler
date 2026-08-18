--TEST--
AOT: createAttribute saveXML must not SIGSEGV (#32351)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
$attr = $doc->createAttribute('id');
echo $attr->nodeName, '|', $doc->saveXML($attr), "END\n";
--EXPECT--
id| id=""END
