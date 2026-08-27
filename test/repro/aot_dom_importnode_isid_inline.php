<?php
/** #23514 inline (no function) — per-call isId fold vs maintainer_gap function wrapper. */
$xml = new DOMDocument();
$xml->loadXML('<div id="w">x</div>');
$html = new DOMDocument();
$html->loadHTML('<!DOCTYPE html><html><body></body></html>');
$n = $html->importNode($xml->documentElement, true);
$html->getElementsByTagName('body')->item(0)->appendChild($n);
$attr = $n->getAttributeNode('id');
echo 'xml2html isId=', ($attr && $attr->isId()) ? 'true' : 'false', "\n";

$src = new DOMDocument();
$src->loadHTML('<div id="w">x</div>');
$div = $src->getElementById('w');
$html2 = new DOMDocument();
$html2->loadHTML('<!DOCTYPE html><html><body></body></html>');
$n2 = $html2->importNode($div, true);
$html2->getElementsByTagName('body')->item(0)->appendChild($n2);
$attr2 = $n2->getAttributeNode('id');
echo 'html2html isId=', ($attr2 && $attr2->isId()) ? 'true' : 'false', "\n";
