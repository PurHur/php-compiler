<?php

declare(strict_types=1);

// #18618 — empty HTML elements must serialize as <tag/> (php-src ext/dom/php_dom.c).
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$root->appendChild($doc->createElement('p'));
$html = preg_replace('/\s+/', '', trim($doc->saveHTML($root)));
if ('<root><p/></root>' !== $html) {
    fwrite(STDERR, "fail: saveHTML empty child expected <root><p/></root> got {$html}\n");
    exit(1);
}
if (method_exists(DOMElement::class, 'getOuterHTML')) {
    $span = $doc->createElement('span');
    if ('<span/>' !== $span->getOuterHTML()) {
        fwrite(STDERR, "fail: getOuterHTML empty span expected <span/>\n");
        exit(1);
    }
}
echo "ok\n";
