--TEST--
AOT: appendChild cross-parent move — detach old parent + saveXML (#33404)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$p1 = $r->appendChild($d->createElement('p1'));
$p2 = $r->appendChild($d->createElement('p2'));
$n = $p1->appendChild($d->createElement('n'));
$p2->appendChild($n);
echo $d->saveXML($r), "\n";
echo $p1->childNodes->length, ' ', $p2->childNodes->length, "\n";
--EXPECT--
<r><p1/><p2><n/></p2></r>
0 1
