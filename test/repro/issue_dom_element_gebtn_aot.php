<?php
declare(strict_types=1);

/**
 * AOT DOMElement::getElementsByTagName live descendant NodeList (#32454).
 * php-src ext/dom/element.c PHP_METHOD(DOMElement, getElementsByTagName).
 */
$doc = new DOMDocument();
$doc->loadXML('<root><a/><b/><a/></root>');
$root = $doc->documentElement;
echo $root->getElementsByTagName('a')->length, '|', $root->getElementsByTagName('root')->length, "\n";
