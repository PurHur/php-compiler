<?php

declare(strict_types=1);

/**
 * Issue #15298 — DOMElement::getElementsByTagName() element-scoped tag search.
 */

$dom = new DOMDocument();
$dom->loadXML('<root><a><b/></a><b/></root>');

$scoped = $dom->documentElement->firstChild->getElementsByTagName('b');
$document = $dom->getElementsByTagName('b');

if (1 !== $scoped->length) {
    echo 'fail: scoped length=', $scoped->length, " expected 1\n";
    exit(1);
}
if (2 !== $document->length) {
    echo 'fail: document length=', $document->length, " expected 2\n";
    exit(1);
}
if ('b' !== $scoped->item(0)->nodeName) {
    echo 'fail: scoped item nodeName=', $scoped->item(0)->nodeName, "\n";
    exit(1);
}

echo "ok\n";
