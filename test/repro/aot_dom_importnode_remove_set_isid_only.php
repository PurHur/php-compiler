<?php
/** Minimal: remove+set on XML-imported id in HTML doc must yield isId=true (#23514). */
$xml = new DOMDocument();
$xml->loadXML('<div id="w">x</div>');
$html = new DOMDocument();
$html->loadHTML('<!DOCTYPE html><html><body></body></html>');
$n = $html->importNode($xml->documentElement, true);
$html->getElementsByTagName('body')->item(0)->appendChild($n);
$n->removeAttribute('id');
$n->setAttribute('id', 'w');
$attr = $n->getAttributeNode('id');
echo ($attr && $attr->isId()) ? 'true' : 'false', "\n";
