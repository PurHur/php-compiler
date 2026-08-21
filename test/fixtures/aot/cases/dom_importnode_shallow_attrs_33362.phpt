--TEST--
AOT: importNode($node, false) copies attributes into saveXML (#33362)
--FILE--
<?php
declare(strict_types=1);
$src = new DOMDocument();
$src->loadXML('<r><a id="x" class="c"><b/></a></r>');
$dst = new DOMDocument();
$dst->loadXML('<r/>');
$imp = $dst->importNode($src->documentElement->firstChild, false);
$dst->documentElement->appendChild($imp);
echo $dst->saveXML();
echo 'children=', $dst->documentElement->firstChild->childNodes->length, "\n";
echo 'id=', $dst->documentElement->firstChild->getAttribute('id'), "\n";
echo 'class=', $dst->documentElement->firstChild->getAttribute('class'), "\n";
$a = new DOMDocument();
$a->loadXML('<r/>');
$b = new DOMDocument();
$b->loadXML('<x id="root"><y>z</y></x>');
$imp2 = $a->importNode($b->documentElement, true);
$a->documentElement->appendChild($imp2);
echo $a->saveXML($a->documentElement), "\n";
--EXPECT--
<?xml version="1.0"?>
<r><a id="x" class="c"/></r>
children=0
id=x
class=c
<r><x id="root"><y>z</y></x></r>
--EXPECT_EXIT--
0
