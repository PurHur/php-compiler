--TEST--
dom importNode preserves DTD ATTLIST ID type for isId/getElementById (#21102, ext/dom/document.c)
--FILE--
<?php
$src = new DOMDocument();
$src->loadXML('<!DOCTYPE x [<!ATTLIST c id ID #IMPLIED>]><r><c id="t">x</c></r>');
$el = $src->documentElement->firstChild;
echo 'src_isId:', $el->getAttributeNode('id')->isId() ? '1' : '0', "\n";

$dst = new DOMDocument();
$dst->appendChild($dst->createElement('root'));
$n = $dst->importNode($el, true);
echo 'imported_isId:', $n->getAttributeNode('id')->isId() ? '1' : '0', "\n";
$dst->documentElement->appendChild($n);
$found = $dst->getElementById('t');
echo 'get:', null !== $found ? $found->tagName : 'null', "\n";

// setIdAttribute must NOT survive importNode (php-src / #20830).
$src2 = new DOMDocument();
$src2->loadXML('<r><c foo="u"/></r>');
$el2 = $src2->documentElement->firstChild;
$el2->setIdAttribute('foo', true);
$dst2 = new DOMDocument();
$dst2->appendChild($dst2->createElement('root'));
$n2 = $dst2->importNode($el2, true);
$dst2->documentElement->appendChild($n2);
echo 'setId_isId:', $n2->getAttributeNode('foo')->isId() ? '1' : '0', "\n";
echo 'setId_get:', null !== $dst2->getElementById('u') ? 'hit' : 'null', "\n";
--EXPECT--
src_isId:1
imported_isId:1
get:c
setId_isId:0
setId_get:null
