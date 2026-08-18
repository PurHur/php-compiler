<?php
declare(strict_types=1);

/**
 * #32327 — AOT createCDATASection must not SIGSEGV on saveXML.
 * php-src ext/dom/document.c PHP_METHOD(DOMDocument, createCDATASection)
 * → xmlNewCDataBlock; saveXML → xmlNodeDump → <![CDATA[...]]>
 */
$doc = new DOMDocument();
$c = $doc->createCDATASection('hi');
echo $c->nodeName, '|', $c->nodeValue, '|', $c->textContent, "\n";
echo $doc->saveXML($c), "\n";
