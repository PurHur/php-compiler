<?php
declare(strict_types=1);

// #20123 — DOMElement::setIdAttributeNode() → getElementById (php-src ext/dom/element.c)

if (!method_exists(DOMElement::class, 'setIdAttributeNode')) {
    fwrite(STDERR, "FAIL: method_exists setIdAttributeNode false\n");
    exit(1);
}

$d = new DOMDocument();
$e = $d->createElement('x');
$d->appendChild($e);
$attr = $d->createAttribute('id');
$attr->value = 'foo';
$e->setAttributeNode($attr);
$e->setIdAttributeNode($attr, true);
echo $d->getElementById('foo') ? "ok\n" : "null\n";

$e->setIdAttributeNode($attr, false);
echo null === $d->getElementById('foo') ? "cleared\n" : "still\n";
