<?php
declare(strict_types=1);

/**
 * AOT DOMText::isWhitespaceInElementContent() must not abort (#32396).
 * php-src ext/dom/text.c PHP_METHOD(DOMText, isWhitespaceInElementContent) → xmlIsBlankNode.
 */
$doc = new DOMDocument();
$t = $doc->createTextNode('ab');
echo (int) $t->isWhitespaceInElementContent(), "\n";
$w = $doc->createTextNode(" \t\n");
echo (int) $w->isWhitespaceInElementContent(), "\n";
$e = $doc->createTextNode('');
echo (int) $e->isWhitespaceInElementContent(), "\n";
echo (int) $w->isElementContentWhitespace(), "\n";
