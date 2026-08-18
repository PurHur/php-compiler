<?php
declare(strict_types=1);

/**
 * #32292 — AOT createElement($name, $value) must not SIGSEGV on textContent / saveXML.
 * php-src ext/dom/document.c dom_document_create_element
 */
$doc = new DOMDocument();
$el = $doc->createElement('p', 'hello');
echo 'text=', $el->textContent, "\n";
echo 'nodeValue=', $el->nodeValue, "\n";
echo 'xml=', $doc->saveXML($el), "\n";
$empty = $doc->createElement('span');
echo 'empty=', var_export($empty->textContent, true), "\n";
