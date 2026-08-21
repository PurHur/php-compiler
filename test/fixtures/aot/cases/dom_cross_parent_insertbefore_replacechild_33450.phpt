--TEST--
AOT: cross-parent insertBefore/replaceChild detach + INNER_XML (#33450)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$p1 = $r->appendChild($d->createElement('p1'));
$p2 = $r->appendChild($d->createElement('p2'));
$anchor = $p2->appendChild($d->createElement('z'));
$n = $p1->appendChild($d->createElement('n'));
$p2->insertBefore($n, $anchor);
echo $d->saveXML($r), "\n";
echo $p1->childNodes->length, ' ', $p2->childNodes->length, "\n";

$d2 = new DOMDocument();
$r2 = $d2->appendChild($d2->createElement('r'));
$a = $r2->appendChild($d2->createElement('a'));
$r2->appendChild($d2->createElement('b'));
$c = $a->appendChild($d2->createElement('c'));
$a->replaceChild($d2->createElement('x'), $c);
echo $d2->saveXML($r2), "\n";

$d3 = new DOMDocument();
$r3 = $d3->appendChild($d3->createElement('r'));
$p1b = $r3->appendChild($d3->createElement('p1'));
$p2b = $r3->appendChild($d3->createElement('p2'));
$old = $p2b->appendChild($d3->createElement('old'));
$moved = $p1b->appendChild($d3->createElement('moved'));
$p2b->replaceChild($moved, $old);
echo $d3->saveXML($r3), "\n";
echo $p1b->childNodes->length, ' ', $p2b->childNodes->length, "\n";
--EXPECT--
<r><p1/><p2><n/><z/></p2></r>
0 2
<r><a><x/></a><b/></r>
<r><p1/><p2><moved/></p2></r>
0 1
