<?php

declare(strict_types=1);

/**
 * AOT Dom\HTMLDocument / XMLDocument::createFromFile must not return null (#35804).
 * php-src: ext/dom/html_document.c / xml_document.c
 * Requires PHP_COMPILER_PROFILE=8.4 (living Dom\ API).
 */
$html = Dom\HTMLDocument::createFromFile('test/repro/aot_dom_html_createfromfile.html', LIBXML_NOERROR);
echo get_class($html), "\n";
$xml = Dom\XMLDocument::createFromFile('test/repro/aot_dom_xml_createfromfile.xml');
echo get_class($xml), "\n";
