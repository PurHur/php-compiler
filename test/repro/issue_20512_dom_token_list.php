<?php
// Repro #20512 / #28227 — Dom\TokenList for Dom\HTMLElement::$classList
$html = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div class="a b" id="d"></div></body></html>'
);
$el = $html->getElementById('d');
echo class_exists('Dom\\TokenList') ? 'Y' : 'N', "\n";
echo class_exists('DOMTokenList') ? 'Y' : 'N', "\n";
echo get_class($el), "\n";
echo get_class($el->classList), "\n";
echo (int) ($el->classList instanceof Dom\TokenList), "\n";
$el->classList->add('c');
echo $el->getAttribute('class'), "\n";
echo (int) $el->classList->contains('a'), "\n";
$el->classList->remove('b');
echo $el->getAttribute('class'), "\n";
echo (int) $el->classList->toggle('d'), "\n";
echo $el->getAttribute('class'), "\n";
// Legacy DOMElement has no classList (#28227)
$dom = new DOMDocument();
$legacy = $dom->createElement('p');
echo (new ReflectionClass(DOMElement::class))->hasProperty('classList') ? '1' : '0', "\n";
