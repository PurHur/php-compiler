<?php
declare(strict_types=1);

/**
 * AOT DOMNode::getNodePath() must not abort as object::getnodepath() (#32474).
 * php-src ext/dom/node.c PHP_METHOD(DOMNode, getNodePath) → xmlGetNodePath.
 */
$doc = new DOMDocument();
$doc->loadXML('<root><child><leaf/></child></root>');
$root = $doc->documentElement;
$leaf = $root->firstChild->firstChild;
echo $doc->getNodePath(), '|', $root->getNodePath(), '|', $leaf->getNodePath(), "\n";

$dup = new DOMDocument();
$dup->loadXML('<root><child/><child/><child/></root>');
$droot = $dup->documentElement;
echo $droot->firstChild->getNodePath(), '|', $droot->lastChild->getNodePath(), "\n";
