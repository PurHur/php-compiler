<?php
// #35180 — AOT createAttributeNS without documentElement must match Zend (re-#19200).
$doc = new DOMDocument();
$attr = $doc->createAttributeNS('http://example.com', 'p:a');
var_export($attr);
echo "\n";
$doc->appendChild($doc->createElement('root'));
$attr2 = $doc->createAttributeNS('http://example.com', 'ex:foo');
echo get_class($attr2), "\n", $attr2->nodeName, "\n", $attr2->nodeType, "\n";
