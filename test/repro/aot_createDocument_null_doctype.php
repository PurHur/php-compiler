<?php
// #35182 — null $doctype path must stay Zend-matching
$impl = new DOMImplementation();
$doc = $impl->createDocument(null, 'root', null);
echo $doc->documentElement->nodeName, '|';
echo $doc->doctype === null ? 'null' : 'set', "\n";
