<?php

declare(strict_types=1);

// Repro #16914 — DOMElement::insertAdjacentText() (ext/dom/element.c).
if (!method_exists(DOMElement::class, 'insertAdjacentText')) {
    echo "missing\n";
    exit(1);
}

$dom = new DOMDocument();
$dom->loadXML('<?xml version="1.0"?><container><p>H</p></container>');
$p = $dom->getElementsByTagName('p')->item(0);
if (!($p instanceof DOMElement)) {
    echo "no p\n";
    exit(1);
}
$p->insertAdjacentText('afterbegin', 'P');
$p->insertAdjacentText('beforeend', 'P');

$xml = trim($dom->saveXML());
$expected = '<?xml version="1.0"?>'."\n".'<container><p>PHP</p></container>';
if ($xml !== $expected) {
    echo "tree fail: $xml\n";
    exit(1);
}

$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$el = $doc->createElement('div');
$root->appendChild($el);
$el->insertAdjacentText('beforebegin', 'A');
$el->insertAdjacentText('afterbegin', 'B');
$el->insertAdjacentText('beforeend', 'C');
$el->insertAdjacentText('afterend', 'D');
$html = preg_replace('/\s+/', '', $doc->saveHTML($root));
if ($html !== '<root>A<div>BC</div>D</root>') {
    echo "html fail: $html\n";
    exit(1);
}

try {
    $el->insertAdjacentText('nope', 'x');
    echo "bad\n";
    exit(1);
} catch (ValueError $e) {
    // expected
}

echo "ok\n";
