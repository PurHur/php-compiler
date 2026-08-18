<?php
declare(strict_types=1);

/**
 * #32331 — AOT createProcessingInstruction must not SIGSEGV on saveXML.
 * php-src ext/dom/document.c PHP_METHOD(DOMDocument, createProcessingInstruction) → xmlNewDocPI
 */
$doc = new DOMDocument();
$pi = $doc->createProcessingInstruction('xml-stylesheet', 'type="text/xsl" href="s.xsl"');
echo $pi->nodeName, '|', $pi->nodeValue, '|', $pi->textContent, "\n";
echo $doc->saveXML($pi), "\n";
$pi2 = $doc->createProcessingInstruction('target');
echo $pi2->nodeName, '|', $pi2->nodeValue, "\n";
echo $doc->saveXML($pi2), "\n";
