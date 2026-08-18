<?php
declare(strict_types=1);

/**
 * #32334 — AOT createDocumentFragment must not SIGSEGV on saveXML.
 * php-src ext/dom/document.c PHP_METHOD(DOMDocument, createDocumentFragment)
 * → xmlNewDocFragment; saveXML → xmlNodeDump of XML_DOCUMENT_FRAG_NODE (children only).
 */
$doc = new DOMDocument();
$f = $doc->createDocumentFragment();
echo $f->nodeName, '|', $doc->saveXML($f), "END\n";
