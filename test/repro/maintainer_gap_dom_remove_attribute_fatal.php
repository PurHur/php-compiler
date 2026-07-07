<?php

declare(strict_types=1);

/**
 * Maintainer repro: DOMElement::removeAttribute() ctx arg (#17084, re-#15297).
 *
 * php-src: ext/dom/element.c — dom_element_remove_attribute
 */

$dom = new DOMDocument();
$dom->loadXML('<root foo="bar"/>');
$el = $dom->documentElement;

if (!$el->hasAttribute('foo')) {
    echo "fail: expected foo attribute\n";
    exit(1);
}

if (!$el->removeAttribute('foo')) {
    echo "fail: removeAttribute(foo) should return true\n";
    exit(1);
}

if ($el->hasAttribute('foo')) {
    echo "fail: foo attribute still present\n";
    exit(1);
}

if ($el->removeAttribute('missing')) {
    echo "fail: removeAttribute(missing) should return false\n";
    exit(1);
}

echo "ok\n";
