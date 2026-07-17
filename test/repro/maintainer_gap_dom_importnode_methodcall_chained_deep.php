<?php
declare(strict_types=1);
/**
 * Issue #20284 — importNode(getElementsByTagName()->item(0), true) ARG_SEND drift.
 * Zend: deep-imports the <a> subtree. Pre-fix: TypeError on Argument #2 ($deep).
 */
$d1 = new DOMDocument();
$d1->loadXML('<root><a><b>x</b></a></root>');
$d2 = new DOMDocument();
$d2->loadXML('<root/>');
$n = $d2->importNode($d1->getElementsByTagName('a')->item(0), true);
$d2->documentElement->appendChild($n);
echo $d2->saveXML();
