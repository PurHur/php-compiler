<?php
declare(strict_types=1);

/**
 * AOT DOMElement::getElementsByTagNameNS live descendant NodeList.
 * php-src ext/dom/element.c PHP_METHOD(DOMElement, getElementsByTagNameNS).
 * Receiver is not in the list (xmlFirstElementChild); a namespaced root would
 * be counted by the document helper.
 */
$doc = new DOMDocument();
$doc->loadXML('<n:r xmlns:n="http://example.com/ns"><n:a/><b/><n:c/></n:r>');
$root = $doc->documentElement;
$list = $root->getElementsByTagNameNS('http://example.com/ns', '*');
echo 'len=', $list->length, '|';
echo 'i0=', $list->item(0)->localName, '|';
echo 'i1=', $list->item(1)->localName, "\n";
