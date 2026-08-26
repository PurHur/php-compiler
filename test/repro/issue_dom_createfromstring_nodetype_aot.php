<?php
declare(strict_types=1);

/**
 * #35177 — AOT Dom\XMLDocument / HTMLDocument::createFromString must seed nodeType.
 * php-src: XML_DOCUMENT_NODE=9 (ext/dom/xml_document.c / html_document.c).
 * Requires PHP_COMPILER_PROFILE=8.4 (living Dom\ API); host Zend 8.2 has no Dom\.
 */
$xml = Dom\XMLDocument::createFromString('<root/>');
echo 'xml_type=', $xml->nodeType, "\n";

$html = Dom\HTMLDocument::createFromString('<p>x</p>');
echo 'html_type=', $html->nodeType, "\n";
