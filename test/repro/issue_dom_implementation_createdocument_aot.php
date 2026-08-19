<?php
declare(strict_types=1);

/**
 * AOT DOMImplementation::createDocument live documentElement.
 * php-src ext/dom/php_dom.c PHP_METHOD(DOMImplementation, createDocument)
 * (xmlNewDoc + xmlNewDocNode + xmlDocSetRootElement).
 */
$impl = new DOMImplementation();
$doc = $impl->createDocument(null, 'root');
echo $doc->documentElement->tagName, '|';
$ns = $impl->createDocument('http://example.com/ns', 'ex:root');
echo $ns->documentElement->tagName, '|';
echo $ns->documentElement->namespaceURI, "\n";
