<?php
declare(strict_types=1);

/**
 * AOT DOMNode::lookupNamespaceURI() must not abort as object::lookupnamespaceuri().
 * php-src ext/dom/node.c PHP_METHOD(DOMNode, lookupNamespaceURI) → xmlSearchNs.
 */
$doc = new DOMDocument();
$doc->loadXML('<root xmlns:foo="http://example.com/foo" xmlns="http://example.com/default"><child/></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild;
echo var_export($root->lookupNamespaceURI('foo'), true), '|';
echo var_export($leaf->lookupNamespaceURI('foo'), true), '|';
echo var_export($root->lookupNamespaceURI(null), true), '|';
echo var_export($root->lookupNamespaceURI('xml'), true), '|';
echo var_export($root->lookupNamespaceURI('nope'), true), "\n";
