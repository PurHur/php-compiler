<?php
// Repro for #14356 — DOMDocument::loadHTML()/saveHTML() (VM/AOT).
$doc = new DOMDocument();
if (!$doc->loadHTML('<p>hi</p>')) {
    fwrite(STDERR, "loadHTML failed\n");
    exit(1);
}
if ('html' !== $doc->documentElement->nodeName) {
    fwrite(STDERR, 'root=' . $doc->documentElement->nodeName . "\n");
    exit(1);
}
$html = $doc->saveHTML();
if (!str_contains($html, '<p>hi</p>') || !str_contains($html, '<!DOCTYPE html')) {
    fwrite(STDERR, "saveHTML mismatch\n");
    exit(1);
}
echo "dom_load_html_ok\n";
