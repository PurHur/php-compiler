<?php

declare(strict_types=1);

$doc = new DOMDocument();
$el = $doc->createElementNS('http://example.com', 'ex:item', 'text');
if ('http://example.com' !== $el->namespaceURI) {
    fwrite(STDERR, "fail: namespaceURI\n");
    exit(1);
}
if ('item' !== $el->localName) {
    fwrite(STDERR, "fail: localName\n");
    exit(1);
}
if ('ex' !== $el->prefix) {
    fwrite(STDERR, "fail: prefix\n");
    exit(1);
}
if ('text' !== $el->textContent) {
    fwrite(STDERR, "fail: textContent\n");
    exit(1);
}

echo "ok\n";
