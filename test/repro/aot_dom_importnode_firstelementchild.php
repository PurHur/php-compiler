<?php
// #35017 — deep importNode(documentElement->firstElementChild) must copy the
// element child (php-src xmlDocCopyNode), not the source documentElement.
// Peer firstChild path fixed in #33918.
$d1 = new DOMDocument();
$d1->loadXML('<src><a/></src>');
$d2 = new DOMDocument();
$d2->loadXML('<r><b/></r>');
$imp = $d2->importNode($d1->documentElement->firstElementChild, true);
echo 'viaFEC=', $imp->tagName, "\n";
$d2->documentElement->appendChild($imp);
echo $d2->saveXML($d2->documentElement), "\n";
