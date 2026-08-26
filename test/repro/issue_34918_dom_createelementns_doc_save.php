<?php
/**
 * AOT: createElementNS + appendChild + document saveXML (#34918).
 *
 * php-src: ext/dom/document.c createElementNS / xmlDocDumpMemory
 *
 * Document-wide saveXML walks firstChild→nextSibling; ElementNS layout must
 * include sibling slots (peer createElement / #24973) or the walk SIGSEGVs.
 */
$d = new DOMDocument();
$el = $d->createElementNS('urn:x', 'x:item');
$d->appendChild($el);
echo $d->saveXML();
