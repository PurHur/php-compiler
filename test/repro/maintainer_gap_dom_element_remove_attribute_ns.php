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
$removed = $el->removeAttributeNS('http://example.com', 'foo');
if (null !== $removed) {
    fwrite(STDERR, 'fail: removeAttributeNS success should return null, got '.var_export($removed, true)."\n");
    exit(1);
}
if ($el->hasAttributeNS('http://example.com', 'foo')) {
    fwrite(STDERR, "fail: ex:foo still present\n");
    exit(1);
}
if (!$el->hasAttributeNS('http://example.com', 'bar')) {
    fwrite(STDERR, "fail: ex:bar removed unexpectedly\n");
    exit(1);
}
$missing = $el->removeAttributeNS('http://example.com', 'missing');
if (null !== $missing) {
    fwrite(STDERR, 'fail: removeAttributeNS missing attr should return null, got '.var_export($missing, true)."\n");
    exit(1);
}

echo "ok\n";
