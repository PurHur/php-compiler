<?php
declare(strict_types=1);

/**
 * AOT DOMNode::hasAttributes() must not abort as object::hasattributes() (#32458).
 * php-src ext/dom/node.c PHP_METHOD(DOMNode, hasAttributes) → xmlNode->properties.
 */
$doc = new DOMDocument();
$doc->loadXML('<root id="x"><child/></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild;
echo (int) $root->hasAttributes(), '|', (int) $leaf->hasAttributes(), "\n";
