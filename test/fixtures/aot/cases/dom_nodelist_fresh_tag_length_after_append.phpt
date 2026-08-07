--TEST--
AOT: fresh getElementsByTagName length after appendChild keeps live count (#28605, re-#28509)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r/>');
$held = $d->getElementsByTagName('a');
echo 'held_before=', $held->length, "\n";
$d->documentElement->appendChild($d->createElement('a'));
echo 'held_after=', $held->length, "\n";
$fresh = $d->getElementsByTagName('a');
echo 'fresh_after=', $fresh->length, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a/></r>');
echo 'seed=', $d2->getElementsByTagName('a')->length, "\n";
$d2->documentElement->appendChild($d2->createElement('a'));
echo 'seed_fresh=', $d2->getElementsByTagName('a')->length, "\n";
--EXPECT--
held_before=0
held_after=1
fresh_after=1
seed=1
seed_fresh=2
