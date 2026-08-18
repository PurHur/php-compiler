<?php
declare(strict_types=1);

/**
 * #32343 — AOT createEntityReference must not SIGSEGV on saveXML.
 * php-src ext/dom/document.c PHP_METHOD(DOMDocument, createEntityReference)
 * → xmlNewReference; saveXML → xmlNodeDump of XML_ENTITY_REF_NODE (`&name;`).
 */
$doc = new DOMDocument();
$ref = $doc->createEntityReference('amp');
echo $ref->nodeName, '|', $doc->saveXML($ref), "END\n";
