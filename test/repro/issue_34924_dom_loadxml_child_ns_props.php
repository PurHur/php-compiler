<?php
/**
 * #34924 — loadXML namespaced children need ElementNS prefix/localName/namespaceURI.
 *
 * Zend: prefix "x", localName "a", namespaceURI "urn:x"
 * Pre-fix AOT: prefix NULL, localName "x:a", namespaceURI SIGSEGV
 */
$d = new DOMDocument();
$d->loadXML('<r xmlns:x="urn:x"><x:a/></r>');
$el = $d->documentElement->firstChild;
var_export($el->prefix);
echo "\n";
var_export($el->localName);
echo "\n";
var_export($el->namespaceURI);
echo "\n";
