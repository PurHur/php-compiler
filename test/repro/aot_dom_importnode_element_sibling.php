<?php
// #35021 — importNode via next/previousElementSibling and nextSibling must copy
// the sibling (php-src xmlDocCopyNode), not the prior FEC/firstChild stamp.
// Leftover of #35017 (FEC/LEC only).
$d1 = new DOMDocument();
$d1->loadXML('<src><a/><b/></src>');
$d2 = new DOMDocument();
$d2->loadXML('<r><c/></r>');
$nes = $d2->importNode($d1->documentElement->firstElementChild->nextElementSibling, true);
echo 'viaNES=', $nes->tagName, "\n";
$d2->documentElement->appendChild($nes);
echo $d2->saveXML($d2->documentElement), "\n";

$d3 = new DOMDocument();
$d3->loadXML('<src><a/><b/></src>');
$d4 = new DOMDocument();
$d4->loadXML('<r><c/></r>');
$pes = $d4->importNode($d3->documentElement->lastElementChild->previousElementSibling, true);
echo 'viaPES=', $pes->tagName, "\n";
$d4->documentElement->appendChild($pes);
echo $d4->saveXML($d4->documentElement), "\n";

$d5 = new DOMDocument();
$d5->loadXML('<src><a/><b/></src>');
$d6 = new DOMDocument();
$d6->loadXML('<r><c/></r>');
$ns = $d6->importNode($d5->documentElement->firstChild->nextSibling, true);
echo 'viaNS=', $ns->nodeName, "\n";
$d6->documentElement->appendChild($ns);
echo $d6->saveXML($d6->documentElement), "\n";
