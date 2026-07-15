<?php
$doc = new DOMDocument();
$doc->appendChild($doc->createElement("r"));
echo "pre\n";
$a = $doc->createAttributeNS("urn:x", "ns:a");
echo "post\n";
echo ($a instanceof DOMAttr ? "ok\n" : "fail\n");
