<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root xmlns:ex="http://example.com"><ex:item ex:id="1"/></root>');
$el = $doc->documentElement->firstChild;
$attrs = $el->attributes;

if (!method_exists($attrs, 'getNamedItemNS')) {
    fwrite(STDERR, "FAIL missing getNamedItemNS\n");
    exit(1);
}

$attr = $attrs->getNamedItemNS('http://example.com', 'id');
if (null === $attr) {
    fwrite(STDERR, "FAIL getNamedItemNS returned null\n");
    exit(1);
}
if ('1' !== $attr->value) {
    fwrite(STDERR, "FAIL value={$attr->value}\n");
    exit(1);
}

$missing = $attrs->getNamedItemNS('http://example.com', 'missing');
if (null !== $missing) {
    fwrite(STDERR, "FAIL expected null for missing attr\n");
    exit(1);
}

echo "value=1\n";
