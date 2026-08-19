<?php
declare(strict_types=1);

/**
 * AOT DOMNode::isSupported() must not abort as object::issupported().
 * php-src ext/dom/php_dom.c dom_has_feature / node.c PHP_METHOD(DOMNode, isSupported).
 */
$doc = new DOMDocument();
$doc->loadXML('<root id="x"><child/></root>');
$root = $doc->documentElement;
echo (int) $root->isSupported('Core', '1.0'), '|';
echo (int) $root->isSupported('Core', '2.0'), '|';
echo (int) $root->isSupported('XML', '1.0'), '|';
echo (int) $root->isSupported('XML', '2.0'), '|';
echo (int) $root->isSupported('XML', '3.0'), '|';
echo (int) $root->isSupported('HTML', '1.0'), "\n";
