<?php
declare(strict_types=1);

/**
 * AOT Dom\HTMLDocument::saveXml leftover of saveHtml / createFromString.
 * php-src: ext/dom/html_document.c, ext/dom/php_dom.c (xmlDocDumpMemory)
 * Requires PHP_COMPILER_PROFILE=8.4 (living Dom\ API).
 */
$html = Dom\HTMLDocument::createFromString('<p id="x">hi</p>', LIBXML_NOERROR);
echo $html ? $html->saveXml() : 'null';
echo "\n---xml---\n";
$xml = Dom\XMLDocument::createFromString('<root><child>t</child></root>');
echo $xml ? $xml->saveXml() : 'null';
echo "\n";
