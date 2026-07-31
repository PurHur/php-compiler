<?php
/** Repro #26062 — Dom\Element missing attrs → null (php-src follow_spec). */
$d = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div id="d" title="t"></div></body></html>',
    LIBXML_NOERROR
);
$el = $d->getElementById('d');
echo 'getAttribute=';
var_export($el->getAttribute('nope'));
echo "\n";
echo 'getAttributeNode=';
var_export($el->getAttributeNode('nope'));
echo "\n";
echo 'getAttributeNS=';
var_export($el->getAttributeNS(null, 'nope'));
echo "\n";
echo 'present=';
var_export($el->getAttribute('title'));
echo "\n";

$doc = new DOMDocument();
$doc->loadHTML('<div id="x"></div>', LIBXML_NOERROR);
$le = $doc->getElementById('x');
echo 'legacy_getAttribute=';
var_export($le->getAttribute('nope'));
echo "\n";
echo 'legacy_getAttributeNode=';
var_export($le->getAttributeNode('nope'));
echo "\n";
