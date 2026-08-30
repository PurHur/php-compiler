<?php
/**
 * #35997 — importNode(DocumentFragment) with empty cloneNode element.
 * php-src: ext/dom/node.c dom_node_clone_node / ext/dom/document.c importNode
 */
$src = new DOMDocument();
$src->loadXML('<root><a/></root>');
$el = $src->documentElement->firstChild;
$f = $src->createDocumentFragment();
$f->appendChild($el->cloneNode(true));
$dst = new DOMDocument();
$n = $dst->importNode($f, true);
echo trim($dst->saveXML($n)), "\n";
