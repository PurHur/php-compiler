<?php

declare(strict_types=1);

/**
 * Issue #14469 — DOMElement::hasAttributes() matches Zend (ext/dom/node.c).
 */

$doc = new DOMDocument();
$doc->loadXML('<r a="1"/>');
if (!$doc->documentElement->hasAttributes()) {
    fwrite(STDERR, "fail: element with attribute should have hasAttributes() true\n");
    exit(1);
}

$doc->loadXML('<r/>');
if ($doc->documentElement->hasAttributes()) {
    fwrite(STDERR, "fail: bare element should have hasAttributes() false\n");
    exit(1);
}

$doc->loadXML('<r xmlns="http://example.com"/>');
if ($doc->documentElement->hasAttributes()) {
    fwrite(STDERR, "fail: default xmlns should not count as attribute\n");
    exit(1);
}

$doc->loadXML('<r xmlns:foo="http://example.com" foo:x="1"/>');
if (!$doc->documentElement->hasAttributes()) {
    fwrite(STDERR, "fail: prefixed attribute should count\n");
    exit(1);
}

echo "ok\n";
