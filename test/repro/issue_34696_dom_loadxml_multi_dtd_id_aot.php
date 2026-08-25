<?php
/** Repro #34696 — AOT loadXML DTD multi-ID getElementById + tree identity. */
$d = new DOMDocument();
$d->loadXML('<!DOCTYPE r [<!ELEMENT r (e*)><!ELEMENT e (#PCDATA)><!ATTLIST e id ID #REQUIRED>]><r><e id="a">1</e><e id="b">2</e></r>');
$byA = $d->getElementById('a');
$byB = $d->getElementById('b');
$treeA = $d->documentElement->firstChild;
$treeB = $d->documentElement->childNodes->item(1);
echo 'a:', $byA ? $byA->textContent : 'null', "\n";
echo 'b:', $byB ? $byB->textContent : 'null', "\n";
echo 'sameA:', ($byA === $treeA) ? 'yes' : 'no', "\n";
echo 'sameB:', ($byB === $treeB) ? 'yes' : 'no', "\n";
echo 'parentA:', ($byA && $byA->parentNode) ? $byA->parentNode->nodeName : 'null', "\n";
echo 'parentB:', ($byB && $byB->parentNode) ? $byB->parentNode->nodeName : 'null', "\n";
