<?php
/**
 * DOMNode::C14N() must return false for relative namespace URIs (php-src ext/dom/node.c / libxml; #22378).
 */
$d = new DOMDocument();
$d->loadXML('<r xmlns:p="u"><p:a>x</p:a></r>');
var_export(@$d->documentElement->C14N());
echo PHP_EOL;
$d2 = new DOMDocument();
$d2->loadXML('<r xmlns="http://example.com"><a>x</a></r>');
var_export(@$d2->documentElement->C14N());
echo PHP_EOL;
