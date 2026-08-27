<?php
/**
 * #23514 — AOT importNode(XML→loadHTML skeleton) then appendChild must not SIGSEGV.
 * Zend: plain id on XML import is not XML_ATTRIBUTE_ID until remove+set on HTML doc.
 */
$xml = new DOMDocument();
$xml->loadXML('<div id="w">x</div>');
$html = new DOMDocument();
$html->loadHTML('<!DOCTYPE html><html><body></body></html>');
$n = $html->importNode($xml->documentElement, true);
$html->getElementsByTagName('body')->item(0)->appendChild($n);
$attr = $n->getAttributeNode('id');
$found = $html->getElementById('w');
echo 'xml2html isId=', ($attr && $attr->isId()) ? 'true' : 'false'
    , ' gebi=', ($found === null ? 'null' : strtolower($found->tagName)), "\n";

$n->setAttribute('id', 'w');
$attr = $n->getAttributeNode('id');
$found = $html->getElementById('w');
echo 'rewrite isId=', ($attr && $attr->isId()) ? 'true' : 'false'
    , ' gebi=', ($found === null ? 'null' : strtolower($found->tagName)), "\n";

$n->removeAttribute('id');
$n->setAttribute('id', 'w');
$attr = $n->getAttributeNode('id');
$found = $html->getElementById('w');
echo 'remove+set isId=', ($attr && $attr->isId()) ? 'true' : 'false'
    , ' gebi=', ($found === null ? 'null' : strtolower($found->tagName)), "\n";
