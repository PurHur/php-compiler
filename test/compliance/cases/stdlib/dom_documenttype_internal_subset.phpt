--TEST--
Stdlib: DOMDocumentType::$internalSubset libxml dump (#21000, ext/dom/documenttype.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<!DOCTYPE r [<!ELEMENT r EMPTY>]><r/>');
echo null === $d->doctype->internalSubset
    ? "subset:null\n"
    : 'subset:'.bin2hex($d->doctype->internalSubset)."\n";

$d2 = new DOMDocument();
$d2->loadXML('<!DOCTYPE r><r/>');
echo null === $d2->doctype->internalSubset ? "none:null\n" : "none:set\n";

$d3 = new DOMDocument();
$d3->loadXML('<!DOCTYPE r []><r/>');
echo null === $d3->doctype->internalSubset ? "empty_brackets:null\n" : "empty_brackets:set\n";

$d4 = new DOMDocument();
$d4->loadXML('<!DOCTYPE r PUBLIC "pub" "sys" [<!ENTITY foo "bar"><!ELEMENT r (#PCDATA)>]><r>&foo;</r>');
echo null === $d4->doctype->internalSubset
    ? "public:null\n"
    : 'public:'.bin2hex($d4->doctype->internalSubset)."\n";
echo 'public_entities:'.$d4->doctype->entities->length."\n";
echo 'public_text:'.json_encode($d4->documentElement->textContent)."\n";

$impl = new DOMImplementation();
$dt = $impl->createDocumentType('r', 'p', 's');
echo null === $dt->internalSubset ? "create:null\n" : "create:set\n";
?>
--EXPECT--
subset:3c21454c454d454e54207220454d5054593e0a
none:null
empty_brackets:null
public:3c21454e5449545920666f6f2022626172223e0a3c21454c454d454e542072202823504344415441293e0a
public_entities:1
public_text:"bar"
create:null
