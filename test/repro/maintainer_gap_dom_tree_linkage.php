<?php

declare(strict_types=1);

/**
 * Issue #14466 — DOMDocument/DOMNode parentNode/childNodes/firstChild after loadXML/appendChild.
 */

$d = new DOMDocument();
$d->loadXML('<r/>');
$parentName = $d->documentElement->parentNode?->nodeName;
$childCount = $d->childNodes?->length;
$firstName = $d->firstChild?->nodeName;

if ('#document' !== $parentName) {
    fwrite(STDERR, "fail: documentElement parentNode expected #document, got ".var_export($parentName, true)."\n");
    exit(1);
}
if (1 !== $childCount) {
    fwrite(STDERR, "fail: document childNodes length expected 1, got ".var_export($childCount, true)."\n");
    exit(1);
}
if ('r' !== $firstName) {
    fwrite(STDERR, "fail: document firstChild expected r, got ".var_export($firstName, true)."\n");
    exit(1);
}

$d2 = new DOMDocument();
$el = $d2->createElement('x');
$d2->appendChild($el);
$appendParent = $el->parentNode?->nodeName;
if ('#document' !== $appendParent) {
    fwrite(STDERR, "fail: appendChild parentNode expected #document, got ".var_export($appendParent, true)."\n");
    exit(1);
}

echo "ok\n";
