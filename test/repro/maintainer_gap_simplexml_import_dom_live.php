<?php

declare(strict_types=1);

// #20137 — simplexml_import_dom must share live node identity (php-src ext/simplexml/simplexml.c).
$d = new DOMDocument();
$el = $d->createElement('a', '1');
$d->appendChild($el);

$sxe = simplexml_import_dom($el);
if (!($sxe instanceof SimpleXMLElement)) {
    fwrite(STDERR, 'fail: expected SimpleXMLElement, got '.get_debug_type($sxe)."\n");
    exit(1);
}

$el->textContent = '2';
$got = (string) $sxe;
if ('2' !== $got) {
    fwrite(STDERR, "fail: after DOM textContent write, SimpleXML still sees {$got} (want 2)\n");
    exit(1);
}

$el->setAttribute('id', 'y');
if ('y' !== (string) $sxe['id']) {
    fwrite(STDERR, "fail: after DOM setAttribute, SimpleXML attr not live\n");
    exit(1);
}

echo "ok\n";
