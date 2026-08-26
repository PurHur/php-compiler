<?php
// #35182 — AOT createDocument must accept non-null $doctype (php-src ext/dom/php_dom.c)
$impl = new DOMImplementation();
$dt = $impl->createDocumentType('html');
$doc = $impl->createDocument(null, 'root', $dt);
echo $doc->documentElement->nodeName, '|';
echo $doc->doctype !== null ? $doc->doctype->nodeName : 'null', '|';
echo $doc->doctype !== null ? (string) $doc->doctype->nodeType : '0', "\n";
