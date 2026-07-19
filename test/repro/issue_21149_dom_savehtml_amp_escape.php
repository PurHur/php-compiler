<?php

declare(strict_types=1);

/**
 * Issue #21149 — DOMDocument::saveHTML($node) must escape text &<> like Zend/libxml.
 */
$doc = new DOMDocument();
$doc->loadHTML(
    '<html><body><p>Hi &amp; bye</p></body></html>',
    LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
);
$p = $doc->getElementsByTagName('p')->item(0);
echo $doc->saveHTML($p), "\n";

$div = $doc->createElement('div');
$div->setAttribute('data-x', 'a&b');
$div->appendChild($doc->createTextNode('x<y>z'));
echo $doc->saveHTML($div), "\n";

$script = $doc->createElement('script');
$script->appendChild($doc->createTextNode('a&&b <c>'));
echo $doc->saveHTML($script), "\n";
