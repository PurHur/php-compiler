<?php
/**
 * AOT: loadXML namespaced child prefix/localName/namespaceURI (#34924).
 *
 * php-src: ext/dom/document.c loadXML; ext/dom/node.c namespace_uri_read
 */
$d = new DOMDocument();
$d->loadXML('<r xmlns:x="urn:x"><x:a/></r>');
$el = $d->documentElement->firstChild;
echo $el->prefix, "\n";
echo $el->localName, "\n";
echo $el->namespaceURI, "\n";
echo $el->lookupNamespaceURI('x'), "\n";
