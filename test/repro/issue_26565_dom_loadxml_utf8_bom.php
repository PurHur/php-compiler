<?php
/**
 * #26565 — DOMDocument::loadXML accepts a leading UTF-8 BOM (libxml / php-src document.c).
 * AOT-safe: avoid var_export(bool), object ternaries, and $encoding reads (pre-existing AOT gaps).
 */
$d = new DOMDocument();
echo @$d->loadXML("\xEF\xBB\xBF<root/>") ? "1" : "0", "\n";
echo $d->documentElement->tagName, "\n";

$d2 = new DOMDocument();
echo @$d2->loadXML("\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"UTF-8\"?><item/>") ? "1" : "0", "\n";
echo $d2->documentElement->tagName, "\n";

// Whitespace before BOM must still fail (Zend/libxml).
$d3 = new DOMDocument();
echo @$d3->loadXML("  \xEF\xBB\xBF<root/>") ? "1" : "0", "\n";

// Non-BOM and intentionally invalid unchanged.
$d4 = new DOMDocument();
echo @$d4->loadXML('<root/>') ? "1" : "0", "\n";
$d5 = new DOMDocument();
echo @$d5->loadXML("\xEF\xBB\xBF<") ? "1" : "0", "\n";
