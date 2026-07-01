<?php

declare(strict_types=1);

/**
 * Issue #14598 — DOMNode::isDefaultNamespace(null) under strict_types (ext/dom/node.c).
 */

$doc = new DOMDocument();
$doc->loadXML('<?xml version="1.0"?><root xmlns="http://example.com"><child/></root>');
$root = $doc->documentElement;
try {
    $root->isDefaultNamespace(null);
    fwrite(STDERR, "fail: expected TypeError\n");
    exit(1);
} catch (TypeError) {
}

echo "ok\n";
