<?php
declare(strict_types=1);

/**
 * AOT DOMNode::hasChildNodes() must not abort as object::haschildnodes() (#32427).
 * php-src ext/dom/node.c PHP_METHOD(DOMNode, hasChildNodes) → xmlNode->children.
 */
$doc = new DOMDocument();
$doc->loadXML('<root><child/></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild;
echo (int) $root->hasChildNodes(), '|', (int) $leaf->hasChildNodes(), "\n";
