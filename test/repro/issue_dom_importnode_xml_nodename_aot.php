<?php
declare(strict_types=1);

/**
 * #32350 — AOT importNode(loadXML documentElement, true) must not SIGSEGV on nodeName.
 * php-src ext/dom/document.c PHP_METHOD(DOMDocument, importNode) → xmlDocCopyNode.
 */
$src = new DOMDocument();
$src->loadXML('<r><c/></r>');
$dst = new DOMDocument();
$n = $dst->importNode($src->documentElement, true);
echo $n->nodeName, '|', $dst->saveXML($n), "END\n";
