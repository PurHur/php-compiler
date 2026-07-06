<?php
declare(strict_types=1);

// Maintainer repro: DOMElement::getAttributeNames() — PHP 8.3+ (#16823, ext/dom/element.c).
$doc = new DOMDocument();
$el = $doc->createElement('div');
$el->setAttribute('id', 'x');
$el->setAttribute('class', 'a');
$names = $el->getAttributeNames();
if (!\is_array($names)) {
    fwrite(STDERR, "FAIL: expected array\n");
    exit(1);
}
if ($names !== ['id', 'class']) {
    fwrite(STDERR, 'FAIL: expected [id, class], got '.var_export($names, true)."\n");
    exit(1);
}
$empty = $doc->createElement('span');
if ($empty->getAttributeNames() !== []) {
    fwrite(STDERR, "FAIL: empty element should return []\n");
    exit(1);
}
echo "ok\n";
