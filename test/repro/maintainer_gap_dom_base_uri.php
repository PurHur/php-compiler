<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><item/></root>');
$el = $doc->documentElement->firstChild;

if (!property_exists($el, 'baseURI')) {
    fwrite(STDERR, "fail: DOMNode::\$baseURI property missing\n");
    exit(1);
}
if (!\is_string($el->baseURI)) {
    fwrite(STDERR, "fail: baseURI must be string\n");
    exit(1);
}
$doc->documentURI = 'http://example.com/doc.xml';
if ('http://example.com/doc.xml' !== $el->baseURI) {
    fwrite(STDERR, "fail: baseURI should reflect documentURI\n");
    exit(1);
}

echo "ok baseURI={$el->baseURI}\n";
