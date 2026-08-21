--TEST--
AOT: importNode($node, false) is shallow — no child InnerXml (#33097)
--FILE--
<?php
declare(strict_types=1);
$a = new DOMDocument();
$a->loadXML('<r/>');
$b = new DOMDocument();
$b->loadXML('<x><y>z</y></x>');
$imp = $a->importNode($b->documentElement, false);
$a->documentElement->appendChild($imp);
echo $a->documentElement->childNodes->length, ' ';
echo $imp->childNodes->length, ' ';
echo $a->saveXML($a->documentElement), "\n";
$c = new DOMDocument();
$c->loadXML('<r/>');
$d = new DOMDocument();
$d->loadXML('<p><q/></p>');
$imp2 = $c->importNode($d->documentElement);
$c->documentElement->appendChild($imp2);
echo $c->saveXML($c->documentElement), "\n";
$e = new DOMDocument();
$e->loadXML('<r/>');
$f = new DOMDocument();
$f->loadXML('<x><y>z</y></x>');
$imp3 = $e->importNode($f->documentElement, true);
$e->documentElement->appendChild($imp3);
echo $e->saveXML($e->documentElement), "\n";
--EXPECT--
1 0 <r><x/></r>
<r><p/></r>
<r><x><y>z</y></x></r>
--EXPECT_EXIT--
0
