<?php

declare(strict_types=1);

/**
 * Issue #14543 — DOMElement::tagName / setAttribute() / getAttribute() on createElement nodes.
 */

$doc = new DOMDocument();
$el = $doc->createElement('item');

if (!property_exists($el, 'tagName') && !isset($el->tagName)) {
    try {
        $tag = $el->tagName;
    } catch (Throwable $e) {
        fwrite(STDERR, "fail: tagName property missing\n");
        exit(1);
    }
}

if ($el->tagName !== $el->nodeName) {
    fwrite(STDERR, "fail: tagName should equal nodeName\n");
    exit(1);
}

if (!method_exists($el, 'setAttribute')) {
    fwrite(STDERR, "fail: setAttribute() missing\n");
    exit(1);
}
if (!method_exists($el, 'getAttribute')) {
    fwrite(STDERR, "fail: getAttribute() missing\n");
    exit(1);
}

$el->setAttribute('id', 'x');
if ($el->getAttribute('id') !== 'x') {
    fwrite(STDERR, "fail: getAttribute round-trip\n");
    exit(1);
}
if ($el->getAttribute('missing') !== '') {
    fwrite(STDERR, "fail: missing attribute should be empty string\n");
    exit(1);
}

$doc->appendChild($el);
if (!str_contains($doc->saveXML(), 'id="x"')) {
    fwrite(STDERR, "fail: saveXML should include attribute\n");
    exit(1);
}

echo "ok\n";
