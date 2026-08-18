<?php
declare(strict_types=1);

/**
 * #32331 — AOT createProcessingInstruction must not SIGSEGV on saveXML.
 * php-src ext/dom/document.c PHP_METHOD(DOMDocument, createProcessingInstruction)
 * → xmlNewDocPI; saveXML → xmlNodeDump → `<?target data?>` / `<?target?>`
 */
$doc = new DOMDocument();
$pi = $doc->createProcessingInstruction('xml-stylesheet', 'type="text/xsl" href="s.xsl"');
echo $pi->nodeName, '|', $pi->nodeValue, '|', $pi->textContent, "\n";
echo $doc->saveXML($pi), "\n";
$empty = $doc->createProcessingInstruction('target');
echo $empty->nodeName, '|', $empty->nodeValue, "\n";
echo $doc->saveXML($empty), "\n";
