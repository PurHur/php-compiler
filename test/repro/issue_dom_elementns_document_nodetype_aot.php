<?php

declare(strict_types=1);

/**
 * #35173 — AOT createElementNS / createDocument nodeType (peer #35168).
 * php-src: ext/dom/document.c
 */
$d = new DOMDocument();
$el = $d->createElementNS('urn:x', 'x:y');
echo 'el_type=', $el->nodeType, ' name=', $el->nodeName, "\n";

$impl = new DOMImplementation();
$doc = $impl->createDocument(null, 'root');
echo 'doc_type=', $doc->nodeType, ' root=', $doc->documentElement->nodeName, "\n";
