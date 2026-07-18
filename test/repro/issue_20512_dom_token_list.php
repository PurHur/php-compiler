<?php
// Repro #20512 — Dom\TokenList for Dom\HTMLElement::$classList
$html = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div class="a b" id="d"></div></body></html>'
);
$el = $html->getElementById('d');
echo class_exists('Dom\\TokenList') ? 'Y' : 'N', "\n";
echo class_exists('DOMTokenList') ? 'Y' : 'N', "\n";
echo get_class($el), "\n";
echo get_class($el->classList), "\n";
echo (int) ($el->classList instanceof Dom\TokenList), "\n";
echo (int) ($el->classList instanceof DOMTokenList), "\n";
$el->classList->add('c');
echo $el->getAttribute('class'), "\n";
echo (int) $el->classList->contains('a'), "\n";
$el->classList->remove('b');
echo $el->getAttribute('class'), "\n";
echo (int) $el->classList->toggle('d'), "\n";
echo $el->getAttribute('class'), "\n";
// Legacy DOMElement keeps DOMTokenList
$dom = new DOMDocument();
$legacy = $dom->createElement('p');
$dom->appendChild($legacy);
echo get_class($legacy->classList), "\n";
