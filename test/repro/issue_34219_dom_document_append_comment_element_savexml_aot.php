<?php
declare(strict_types=1);

/**
 * AOT: Document appendChild(comment) then appendChild(element) must keep both in saveXML (#34219).
 * php-src ext/dom/document.c — xmlAddChild + saveXML → xmlDocDumpMemory.
 */
$d = new DOMDocument();
$d->appendChild($d->createComment('c'));
$d->appendChild($d->createElement('root'));
echo $d->childNodes->length, "\n";
echo $d->firstChild->nodeName, '|', $d->lastChild->nodeName, "\n";
echo $d->saveXML();
