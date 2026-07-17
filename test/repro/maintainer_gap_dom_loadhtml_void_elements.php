<?php

declare(strict_types=1);

$cases = [
    'meta' => '<html><head><meta charset="utf-8"></head><body><div id="d">x</div></body></html>',
    'base' => '<html><head><base href="http://ex/dir/"></head><body><div id="d">x</div></body></html>',
    'br' => '<html><body>a<br>b<div id="d">x</div></body></html>',
    'img' => '<html><body><img src="x"><div id="d">x</div></body></html>',
];

foreach ($cases as $name => $html) {
    $doc = new DOMDocument();
    if (!$doc->loadHTML($html)) {
        echo "fail: {$name} loadHTML returned false\n";
        exit(1);
    }
    $el = $doc->getElementById('d');
    if (null === $el) {
        echo "fail: {$name} getElementById null (tree collapsed)\n";
        exit(1);
    }
    if ('x' !== $el->textContent) {
        echo "fail: {$name} textContent={$el->textContent}\n";
        exit(1);
    }
}

$doc = new DOMDocument();
$doc->loadHTML('<html><head><base href="http://ex/dir/"></head><body><div id="d">x</div></body></html>');
$el = $doc->getElementById('d');
if ('http://ex/dir/' !== $el->baseURI) {
    echo "fail: HTML baseURI expected http://ex/dir/ got {$el->baseURI}\n";
    exit(1);
}

$xml = new DOMDocument();
$xml->loadXML('<r xml:base="http://ex/a/b/"><c xml:base="../x/"><d/></c></r>');
$d = $xml->documentElement->firstChild->firstChild;
if ('http://ex/a/x/' !== $d->baseURI) {
    echo "fail: xml:base expected http://ex/a/x/ got {$d->baseURI}\n";
    exit(1);
}

echo "ok void+baseURI\n";
