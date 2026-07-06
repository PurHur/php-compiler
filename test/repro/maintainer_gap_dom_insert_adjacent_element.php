<?php

declare(strict_types=1);

// Repro #16865 — DOMElement::insertAdjacentElement() (ext/dom/php_dom.c).
if (!method_exists(DOMElement::class, 'insertAdjacentElement')) {
    echo "missing\n";
    exit(1);
}

$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$el = $doc->createElement('div');
$root->appendChild($el);

$inner = $doc->createElement('b');
$returned = $el->insertAdjacentElement('afterbegin', $inner);
if (!($returned instanceof DOMElement) || $returned !== $inner) {
    echo "afterbegin return fail\n";
    exit(1);
}

$outer = $doc->createElement('p');
$el->insertAdjacentElement('beforebegin', $outer);
$sib = $doc->createElement('em');
$el->insertAdjacentElement('afterend', $sib);
$end = $doc->createElement('i');
$el->insertAdjacentElement('beforeend', $end);

$xml = preg_replace('/\s+/', '', $doc->saveHTML($root));
$expected = '<root><p/><div><b/><i/></div><em/></root>';
if ($xml !== $expected) {
    echo "tree fail: $xml\n";
    exit(1);
}

if (null !== $el->insertAdjacentElement('beforeend', null)) {
    echo "null fail\n";
    exit(1);
}

try {
    $el->insertAdjacentElement('nope', $inner);
    echo "bad\n";
    exit(1);
} catch (ValueError $e) {
    // expected
}

echo "ok\n";
