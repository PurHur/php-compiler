<?php
declare(strict_types=1);

/**
 * #32315 — AOT createComment/createTextNode must not SIGSEGV on saveXML.
 * php-src ext/dom/document.c PHP_METHOD(DOMDocument, saveXML) → xmlNodeDump
 */
$doc = new DOMDocument();
$c = $doc->createComment('hi');
echo $c->nodeName, '|', $c->nodeValue, '|', $c->textContent, "\n";
echo $doc->saveXML($c), "\n";
$t = $doc->createTextNode('hi');
echo $t->nodeName, '|', $t->nodeValue, '|', $t->textContent, "\n";
echo $doc->saveXML($t), "\n";
$el = $doc->createElement('p', 'hello');
echo $doc->saveXML($el), "\n";
