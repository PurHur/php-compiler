<?php
/** Repro #34863 — AOT getAttribute must stay per-element after lastChild / getElementById. */
$doc = new DOMDocument();
$doc->loadXML('<!DOCTYPE r [<!ATTLIST e id ID #IMPLIED>]><r><e id="a"/><e id="b"/></r>');
$treeA = $doc->documentElement->firstChild;
echo 'onlyA=', $treeA->getAttribute('id'), "\n";
$treeB = $doc->documentElement->lastChild;
echo 'afterB_A=', $treeA->getAttribute('id'), "\n";
echo 'afterB_B=', $treeB->getAttribute('id'), "\n";
$byA = $doc->getElementById('a');
$byB = $doc->getElementById('b');
echo 'gebiA=', ($byA ? $byA->getAttribute('id') : 'null'), "\n";
echo 'gebiB=', ($byB ? $byB->getAttribute('id') : 'null'), "\n";
echo 'sameA=', ($byA === $treeA ? 'yes' : 'no'), "\n";
echo 'sameB=', ($byB === $treeB ? 'yes' : 'no'), "\n";
