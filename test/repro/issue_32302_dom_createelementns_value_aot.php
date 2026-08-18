<?php
declare(strict_types=1);

/**
 * #32302 — AOT createElementNS($ns, $name, $value) must not SIGSEGV on nodeValue / saveXML.
 * php-src ext/dom/document.c PHP_METHOD(DOMDocument, createElementNS)
 */
$doc = new DOMDocument();
$el = $doc->createElementNS('http://example.com', 'ex:item', 'hello');
echo 'text=', $el->textContent, "\n";
echo 'nodeValue=', $el->nodeValue, "\n";
echo 'xml=', $doc->saveXML($el), "\n";
$empty = $doc->createElementNS('http://example.com', 'ex:empty');
echo 'empty=', var_export($empty->textContent, true), "\n";
