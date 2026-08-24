<?php

declare(strict_types=1);

/**
 * #34302 / re-#24571 — nested appendChild(createElement) then importNode must AOT-compile.
 * php-src: ext/dom/document.c PHP_METHOD(DOMDocument, importNode)
 */
$src = new DOMDocument();
$src->loadXML('<r><a><b>t</b></a></r>');
$dst = new DOMDocument('1.0');
$dst->appendChild($dst->createElement('root'));
$n = $dst->importNode($src->documentElement, true);
echo $n->nodeName, '/', $n->childNodes->length, "\n";
$dst->documentElement->appendChild($n);
echo trim($dst->saveXML()), "\n";
