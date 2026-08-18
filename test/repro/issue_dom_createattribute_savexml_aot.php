<?php
declare(strict_types=1);

/**
 * #32351 — AOT createAttribute must not SIGSEGV on saveXML.
 * php-src ext/dom/document.c PHP_METHOD(DOMDocument, createAttribute)
 * → xmlNewDocProp; saveXML → xmlNodeDump of XML_ATTRIBUTE_NODE (` name="value"`).
 */
$doc = new DOMDocument();
$attr = $doc->createAttribute('id');
echo $attr->nodeName, '|', $doc->saveXML($attr), "END\n";
