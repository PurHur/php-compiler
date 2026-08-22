<?php
/**
 * importNode into an empty DOMDocument then document-wide saveXML() must dump the
 * destination tree — not replay the source document's last loadXML() literal.
 *
 * php-src: ext/dom/document.c PHP_METHOD(DOMDocument, importNode) → xmlDocCopyNode;
 * saveXML → xmlDocDumpMemory on the receiver document.
 */
$src = new DOMDocument();
$src->loadXML('<r><a id="1"><b/></a></r>');
$dst = new DOMDocument();
$n = $dst->importNode($src->documentElement->firstChild, false);
$dst->appendChild($n);
echo 'name=', $n->nodeName, ' kids=', $n->childNodes->length, "\n";
echo 'de=', $dst->documentElement->nodeName, "\n";
echo 'full=', trim($dst->saveXML()), "\n";
echo 'node=', trim($dst->saveXML($n)), "\n";
echo 'src=', trim($src->saveXML()), "\n";
