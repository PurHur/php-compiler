<?php
declare(strict_types=1);

/**
 * #35173 — AOT createElementNS / createDocument must seed nodeType.
 * php-src: XML_ELEMENT_NODE=1, XML_DOCUMENT_NODE=9
 * (ext/dom/document.c createElementNS; ext/dom/domimplementation.c createDocument).
 */
$d = new DOMDocument();
$el = $d->createElementNS('urn:x', 'x:y');
echo 'elns_type=', $el->nodeType, ' elns_name=', $el->nodeName, "\n";

$impl = new DOMImplementation();
$doc = $impl->createDocument(null, 'root');
echo 'doc_type=', $doc->nodeType, ' root_type=', $doc->documentElement->nodeType, "\n";
