<?php
declare(strict_types=1);
/**
 * Issue #24571 — importNode($doc->documentElement, true) after appendChild(createElement)
 * must not TypeError on $deep (re-#18860 ARG_SEND drift).
 */
$src = new DOMDocument();
$src->loadXML('<r><a><b>t</b></a></r>');
$dst = new DOMDocument('1.0');
$dst->appendChild($dst->createElement('root'));
$n = $dst->importNode($src->documentElement, true);
echo $n->nodeName, '/', $n->childNodes->length, "\n";
$dst->documentElement->appendChild($n);
echo trim($dst->saveXML()), "\n";
$n2 = $dst->importNode($src->documentElement->firstChild, true);
echo $n2->nodeName, '/', $n2->childNodes->length, "\n";
