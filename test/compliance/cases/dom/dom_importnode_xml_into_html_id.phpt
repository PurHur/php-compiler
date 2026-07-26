--TEST--
dom importNode XML→HTML keeps plain id non-ID; remove+setAttribute stamps (#23514, ext/dom/node.c)
--FILE--
<?php
function dump23514($label, $html, $div)
{
    $attr = $div->getAttributeNode('id');
    $found = $html->getElementById('w');
    echo $label
        , ' isId=', ($attr && $attr->isId()) ? 'true' : 'false'
        , ' gebi=', $found ? strtolower($found->tagName) : 'null'
        , "\n";
}

$xml = new DOMDocument();
$xml->loadXML('<div id="w">x</div>');
$html = new DOMDocument();
$html->loadHTML('<!DOCTYPE html><html><body></body></html>');
$srcEl = $xml->documentElement;
$n = $html->importNode($srcEl, true);
$html->getElementsByTagName('body')->item(0)->appendChild($n);
dump23514('xml2html', $html, $n);

$n->setAttribute('id', 'w');
dump23514('rewrite', $html, $n);

$n->removeAttribute('id');
$n->setAttribute('id', 'w');
dump23514('remove+set', $html, $n);

$src = new DOMDocument();
$src->loadHTML('<div id="w">x</div>');
$div = $src->getElementById('w');
$html2 = new DOMDocument();
$html2->loadHTML('<!DOCTYPE html><html><body></body></html>');
$n2 = $html2->importNode($div, true);
$html2->getElementsByTagName('body')->item(0)->appendChild($n2);
dump23514('html2html', $html2, $n2);

$html3 = new DOMDocument();
$html3->loadHTML('<!DOCTYPE html><html><body></body></html>');
$el = $html3->createElement('div');
$html3->getElementsByTagName('body')->item(0)->appendChild($el);
$el->setAttribute('id', 'w');
dump23514('html-create', $html3, $el);

$xmlDtd = new DOMDocument();
$xmlDtd->loadXML('<!DOCTYPE r [<!ATTLIST c id ID #IMPLIED>]><r><c id="w">x</c></r>');
$dtdEl = $xmlDtd->documentElement->firstChild;
$html4 = new DOMDocument();
$html4->loadHTML('<!DOCTYPE html><html><body></body></html>');
$n4 = $html4->importNode($dtdEl, true);
$html4->getElementsByTagName('body')->item(0)->appendChild($n4);
dump23514('dtd2html', $html4, $n4);

$html5 = new DOMDocument();
$html5->loadHTML('<div id="w">x</div>');
$found = $html5->getElementById('w');
echo 'loadhtml gebi=', $found ? strtolower($found->tagName) : 'null', "\n";
--EXPECT--
xml2html isId=false gebi=null
rewrite isId=false gebi=null
remove+set isId=true gebi=div
html2html isId=true gebi=div
html-create isId=true gebi=div
dtd2html isId=true gebi=c
loadhtml gebi=div
