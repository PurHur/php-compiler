<?php

declare(strict_types=1);

$doc = new DOMDocument();
$el = $doc->createElementNS('http://example.com/ns', 'ex:a');
$el->setAttributeNS('http://example.com/ns', 'ex:attr', 'v');

$v = $el->getAttributeNS('http://example.com/ns', 'attr');
if ('v' !== $v) {
    fwrite(STDERR, "fail: getAttributeNS expected v got {$v}\n");
    exit(1);
}
if (!$el->hasAttributeNS('http://example.com/ns', 'attr')) {
    fwrite(STDERR, "fail: hasAttributeNS\n");
    exit(1);
}
$el->removeAttributeNS('http://example.com/ns', 'attr');
if ($el->hasAttributeNS('http://example.com/ns', 'attr')) {
    fwrite(STDERR, "fail: removeAttributeNS\n");
    exit(1);
}

echo "ok\n";
