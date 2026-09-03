<?php
// Part of #36204 — Dom/SimpleXML computed props via ObjectComputedPropertySupport.
$doc = new DOMDocument();
$doc->loadXML('<root><a>hi</a></root>');
$el = $doc->documentElement;
echo isset($el->firstChild) ? '1' : '0';
echo '|';
$sxe = simplexml_load_string('<r><c>v</c></r>');
echo empty($sxe->c) ? '0' : '1';
echo '|';
echo empty($sxe->missing) ? '1' : '0';
echo "\n";
