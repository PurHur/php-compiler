<?php

declare(strict_types=1);

/**
 * AOT Dom\HTMLDocument/XMLDocument::createFromFile leftover of createFromString.
 * php-src: ext/dom/html_document.c / xml_document.c
 * Requires PHP_COMPILER_PROFILE=8.4 (living Dom\ API).
 *
 * Paths are compile-time literals so thin AOT can fold through createFromString
 * materialize (runtime NestedJIT ObjectEntry* SIGSEGVs — same as CFS getenv HTML).
 */
$html = Dom\HTMLDocument::createFromFile(
    'test/repro/aot_dom_createfromfile.html',
    LIBXML_NOERROR
);
echo $html ? get_class($html) : 'null', "\n";

$xml = Dom\XMLDocument::createFromFile('test/repro/aot_dom_createfromfile.xml', 0);
echo $xml ? get_class($xml) : 'null', "\n";
echo $xml ? ($xml->documentElement?->nodeName ?? '(none)') : 'n/a', "\n";
