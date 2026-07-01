<?php

declare(strict_types=1);

/**
 * Issue #14456 — DOMNode::isSupported() / isDefaultNamespace() parity (ext/dom/node.c).
 */

$doc = new DOMDocument();
$el = $doc->createElement('root');
if ($el->isSupported('Core', '2.0')) {
    fwrite(STDERR, "fail: isSupported Core 2.0 should be false\n");
    exit(1);
}
if (!$el->isSupported('Core', '1.0')) {
    fwrite(STDERR, "fail: isSupported Core 1.0 should be true\n");
    exit(1);
}

$doc->loadXML('<root xmlns="http://example.com"/>');
$root = $doc->documentElement;
if (!$root->isDefaultNamespace('http://example.com')) {
    fwrite(STDERR, "fail: default_yes\n");
    exit(1);
}
if ($root->isDefaultNamespace('http://other.example.com')) {
    fwrite(STDERR, "fail: other namespace should be false\n");
    exit(1);
}
if ($root->isDefaultNamespace(null)) {
    fwrite(STDERR, "fail: isDefaultNamespace(null) should be false\n");
    exit(1);
}

echo "ok\n";
