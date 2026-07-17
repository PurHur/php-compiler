--TEST--
xmlwriter writeDtdElement/writeDtdAttlist/DTD entity methods — (#19468, ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
$w = new XMLWriter();
$w->openMemory();
$w->startDocument('1.0');
foreach (['writeDtdElement', 'writeDtdAttlist', 'startDtdEntity', 'endDtdEntity', 'writeDtdEntity'] as $m) {
    echo $m, '=', method_exists($w, $m) ? '1' : '0', "\n";
}
$w->startDtd('root');
var_export($w->writeDtdElement('foo', '(#PCDATA)'));
echo "\n";
var_export($w->writeDtdAttlist('foo', 'bar CDATA #IMPLIED'));
echo "\n";
var_export($w->startDtdEntity('ent', false));
echo "\n";
var_export($w->text('bar'));
echo "\n";
var_export($w->endDtdEntity());
echo "\n";
var_export($w->writeDtdEntity('ent2', 'val', false));
echo "\n";
$w->endDtd();
$w->startElement('root');
$w->endElement();
echo 'out=', $w->outputMemory(), "\n";

$w2 = new XMLWriter();
$w2->openMemory();
$w2->startDtd('r');
var_export($w2->startDtdEntity('pe', true));
echo "\n";
$w2->text('x');
$w2->endDtdEntity();
$w2->endDtd();
echo 'pe=', $w2->outputMemory(), "\n";

$w3 = new XMLWriter();
$w3->openMemory();
$w3->writeDtdElement('foo', '(#PCDATA)');
echo 'bare=', $w3->outputMemory(), "\n";
?>
--EXPECT--
writeDtdElement=1
writeDtdAttlist=1
startDtdEntity=1
endDtdEntity=1
writeDtdEntity=1
true
true
true
true
true
true
out=<?xml version="1.0"?>
<!DOCTYPE root [<!ELEMENT foo (#PCDATA)><!ATTLIST foo bar CDATA #IMPLIED><!ENTITY ent "bar"><!ENTITY ent2 "val">]><root/>
true
pe=<!DOCTYPE r [<!ENTITY % pe "x">]>
bare=<!ELEMENT foo (#PCDATA)>
