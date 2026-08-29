<?php
/** adoptNode with createElement tree (not loadXML) — AOT probe. */
$d1 = new DOMDocument();
$a = $d1->createElement('a');
$n = $d1->createElement('n');
$n->appendChild($d1->createTextNode('t'));
$a->appendChild($n);
$d1->appendChild($a);
$d2 = new DOMDocument();
$d2->loadXML('<b/>');
$adopted = $d2->adoptNode($n);
echo $adopted->nodeName, "\n";
echo $d1->saveXML($d1->documentElement), "\n";
$d2->documentElement->appendChild($adopted);
echo $d2->saveXML($d2->documentElement), "\n";
