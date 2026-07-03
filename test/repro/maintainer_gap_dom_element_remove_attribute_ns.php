<?php

declare(strict_types=1);

$dom = new DOMDocument();
$dom->loadXML('<root xmlns:ex="http://example.com"><ex:child ex:foo="bar" ex:bar="baz"/></root>');
$el = $dom->documentElement->firstChild;
if (!$el instanceof DOMElement) {
    fwrite(STDERR, "fail: child element not found\n");
    exit(1);
}
if (!$el->hasAttributeNS('http://example.com', 'foo')) {
    fwrite(STDERR, "fail: setup missing ex:foo\n");
    exit(1);
}
if (null !== $el->removeAttributeNS('http://example.com', 'foo')) {
    fwrite(STDERR, "fail: removeAttributeNS should return null on success\n");
    exit(1);
}
if (false !== $el->hasAttributeNS('http://example.com', 'foo')) {
    fwrite(STDERR, "fail: ex:foo still present after removeAttributeNS\n");
    exit(1);
}
if (!$el->hasAttributeNS('http://example.com', 'bar')) {
    fwrite(STDERR, "fail: ex:bar removed unexpectedly\n");
    exit(1);
}
if (null !== $el->removeAttributeNS('http://example.com', 'missing')) {
    fwrite(STDERR, "fail: removeAttributeNS should return null for missing attr\n");
    exit(1);
}

echo "ok\n";
