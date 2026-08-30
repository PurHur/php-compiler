<?php
/**
 * #35997 — importNode(DocumentFragment) with element+text built before append.
 * php-src: ext/dom/document.c PHP_METHOD(DOMDocument, importNode) → xmlDocCopyNode
 */
$src = new DOMDocument();
$f = $src->createDocumentFragment();
$el = $src->createElement('a');
$el->appendChild($src->createTextNode('1'));
$f->appendChild($el);
$dst = new DOMDocument();
$n = $dst->importNode($f, true);
echo trim($dst->saveXML($n)), "\n";
