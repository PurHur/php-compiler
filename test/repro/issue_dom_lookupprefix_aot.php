<?php
declare(strict_types=1);

/**
 * AOT DOMNode::lookupPrefix() must not abort as object::lookupprefix() (#32493).
 * php-src ext/dom/node.c PHP_METHOD(DOMNode, lookupPrefix) → xmlSearchNsByHref.
 */
$doc = new DOMDocument();
$doc->loadXML('<root xmlns:foo="http://example.com/foo"><child/></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild;
echo var_export($root->lookupPrefix('http://example.com/foo'), true), '|';
echo var_export($leaf->lookupPrefix('http://example.com/foo'), true), '|';
echo var_export($root->lookupPrefix('http://nope'), true), "\n";
