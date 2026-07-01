<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root xmlns:ex="http://example.com"><ex:item ex:attr="v"/></root>');
$el = $doc->documentElement->firstChild;

$v = $el->getAttributeNS('http://example.com', 'attr');
if ('v' !== $v) {
    fwrite(STDERR, "fail: getAttributeNS expected v got {$v}\n");
    exit(1);
}
if (!$el->hasAttributeNS('http://example.com', 'attr')) {
    fwrite(STDERR, "fail: hasAttributeNS\n");
    exit(1);
}
$prefix = $el->lookupPrefix('http://example.com');
if ('ex' !== $prefix) {
    fwrite(STDERR, "fail: lookupPrefix expected ex got {$prefix}\n");
    exit(1);
}
$ns = $el->lookupNamespaceURI('ex');
if ('http://example.com' !== $ns) {
    fwrite(STDERR, "fail: lookupNamespaceURI\n");
    exit(1);
}
$el->setAttributeNS('http://example.com', 'ex:newattr', 'new');
if ('new' !== $el->getAttributeNS('http://example.com', 'newattr')) {
    fwrite(STDERR, "fail: setAttributeNS\n");
    exit(1);
}

echo "v / has / ex / http://example.com / new\n";
