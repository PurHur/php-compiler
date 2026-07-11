<?php

declare(strict_types=1);

$doc = new DOMDocument();
$el = $doc->createElementNS('http://example.com/ns', 'ex:a');

if ('ex' !== $el->prefix) {
    fwrite(STDERR, 'fail: prefix expected ex got ' . var_export($el->prefix, true) . "\n");
    exit(1);
}
if ('http://example.com/ns' !== $el->namespaceURI) {
    fwrite(STDERR, 'fail: namespaceURI expected http://example.com/ns got ' . var_export($el->namespaceURI, true) . "\n");
    exit(1);
}

echo "ex / http://example.com/ns\n";
