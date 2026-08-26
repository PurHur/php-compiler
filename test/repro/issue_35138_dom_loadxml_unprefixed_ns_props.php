<?php
/**
 * AOT: loadXML unprefixed root namespaceURI + getElementsByTagNameNS(ns,'*') (#35138).
 *
 * php-src: ext/dom/node.c namespace_uri_read; ext/dom/nodelist.c tag-name-ns helper
 */
$d = new DOMDocument();
$d->loadXML('<r xmlns:p="urn:x"><p:a/><p:b/><c/></r>');
$root = $d->documentElement;
echo var_export($root->namespaceURI, true), "\n";
echo var_export($root->localName, true), "\n";
echo var_export($root->prefix, true), "\n";
$nl = $d->getElementsByTagNameNS('urn:x', '*');
echo $nl->length, "\n";
echo $nl->item(0)->localName, "\n";
echo $nl->item(1)->localName, "\n";
$c = $d->getElementsByTagName('c')->item(0);
echo var_export($c->namespaceURI, true), "\n";
