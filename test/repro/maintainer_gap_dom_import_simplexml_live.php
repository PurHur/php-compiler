<?php

declare(strict_types=1);

// #20137 / #20697 — dom_import_simplexml must share live node identity (php-src ext/dom/node.c).
// Named-child property views ($sxe->a) must export the child element, not the parent (#20489).
$sxe = simplexml_load_string('<root><a>1</a></root>');
if (false === $sxe) {
    fwrite(STDERR, "fail: simplexml_load_string\n");
    exit(1);
}

$node = dom_import_simplexml($sxe->a);
if (!($node instanceof DOMElement)) {
    fwrite(STDERR, 'fail: expected DOMElement, got '.get_debug_type($node)."\n");
    exit(1);
}

$node->textContent = '2';
$got = (string) $sxe->a;
if ('2' !== $got) {
    fwrite(STDERR, "fail: after DOM textContent write, SimpleXML still sees {$got} (want 2)\n");
    exit(1);
}

$node->setAttribute('id', 'x');
if ('x' !== (string) $sxe->a['id']) {
    fwrite(STDERR, "fail: after DOM setAttribute, SimpleXML attr not live\n");
    exit(1);
}

echo "ok\n";
