<?php
/**
 * #35881 leftover of #35871 / #35878 — importNode(createDocumentFragment) deep-copy.
 * php-src: ext/dom/document.c PHP_METHOD(DOMDocument, importNode) → xmlDocCopyNode
 */
$src = new DOMDocument();
$frag = $src->createDocumentFragment();
$frag->appendChild($src->createElement('a'));
$frag->appendChild($src->createTextNode('t'));

$dst = new DOMDocument();
$dst->loadXML('<r/>');
$imp = $dst->importNode($frag, true);

echo 'name=', $imp->nodeName,
    ' type=', $imp->nodeType,
    ' len=', $imp->childNodes->length,
    ' xml=', $dst->saveXML($imp),
    "\n";
