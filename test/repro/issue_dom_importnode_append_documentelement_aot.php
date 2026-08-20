<?php
declare(strict_types=1);

/**
 * #32736 — empty DOMDocument::$documentElement is null; importNode+appendChild then
 * documentElement/saveXML must match Zend (php-src ext/dom/document.c).
 */
$empty = new DOMDocument();
echo null === $empty->documentElement ? 'null' : 'set', "\n";

$src = new DOMDocument();
$src->loadXML('<a/>');
$dst = new DOMDocument();
$n = $dst->importNode($src->documentElement, true);
$dst->appendChild($n);
echo $dst->saveXML($dst->documentElement), "\n";
echo $dst->documentElement->nodeName, "\n";
