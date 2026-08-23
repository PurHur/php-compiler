<?php

/**
 * AOT: lookupPrefix(null) / isDefaultNamespace(null) must match Zend (#34099).
 * Non-strict: null coerces like empty URI → NULL / false (php-src ext/dom/node.c).
 * (strict_types TypeError covered separately in the unit test.)
 */
$doc = new DOMDocument();
$doc->loadXML('<root xmlns:foo="http://example.com/foo" xmlns="http://example.com/def"><child/></root>');
$root = $doc->documentElement;
echo var_export($root->lookupPrefix(null), true), '|';
echo var_export($root->lookupPrefix(''), true), '|';
echo var_export($root->lookupPrefix('http://example.com/foo'), true), '|';
echo var_export($root->isDefaultNamespace(null), true), '|';
echo var_export($root->isDefaultNamespace(''), true), '|';
echo var_export($root->isDefaultNamespace('http://example.com/def'), true), "\n";
