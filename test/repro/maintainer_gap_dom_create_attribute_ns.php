<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root/>');
$attr = $doc->createAttributeNS('http://example.com', 'ex:foo');
if (!$attr instanceof DOMAttr) {
    fwrite(STDERR, 'fail: expected DOMAttr, got '.get_debug_type($attr)."\n");
    exit(1);
}
if ('ex:foo' !== $attr->nodeName) {
    fwrite(STDERR, 'fail: nodeName '.$attr->nodeName."\n");
    exit(1);
}
if ('http://example.com' !== $attr->namespaceURI) {
    fwrite(STDERR, 'fail: namespaceURI '.$attr->namespaceURI."\n");
    exit(1);
}
if ('foo' !== $attr->localName) {
    fwrite(STDERR, 'fail: localName '.$attr->localName."\n");
    exit(1);
}
if ('ex' !== $attr->prefix) {
    fwrite(STDERR, 'fail: prefix '.$attr->prefix."\n");
    exit(1);
}
$attr->value = 'bar';
if ('bar' !== $attr->value) {
    fwrite(STDERR, 'fail: value '.$attr->value."\n");
    exit(1);
}

echo "ok\n";
