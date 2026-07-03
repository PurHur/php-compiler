<?php

declare(strict_types=1);

/**
 * Issue #15297 — DOMElement::hasAttribute() / removeAttribute() local-name API.
 */

$dom = new DOMDocument();
$dom->loadXML('<root foo="bar"/>');
$el = $dom->documentElement;

if (!$el->hasAttribute('foo')) {
    echo "fail: hasAttribute(foo) expected true\n";
    exit(1);
}
if (!$el->removeAttribute('foo')) {
    echo "fail: removeAttribute(foo) expected true\n";
    exit(1);
}
if ($el->hasAttribute('foo')) {
    echo "fail: hasAttribute(foo) expected false after remove\n";
    exit(1);
}
if ($el->removeAttribute('missing')) {
    echo "fail: removeAttribute(missing) expected false\n";
    exit(1);
}

echo "ok\n";
