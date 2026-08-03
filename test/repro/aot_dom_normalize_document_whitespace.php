<?php
/**
 * #27260 — AOT DOMDocument::normalizeDocument() after loadXML with inter-element blanks.
 * Zend/VM/JIT print childNodes length 3; AOT must match (no segfault).
 */
$d = new DOMDocument();
$d->loadXML("<r> <a/> </r>");
$d->normalizeDocument();
echo $d->documentElement->childNodes->length, "\n";
