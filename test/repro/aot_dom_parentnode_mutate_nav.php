<?php
// #35010 — ParentNode element-nav after remove/insert/replace on loadXML trees

$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$r = $d->documentElement;
echo (int) $r->childElementCount, '|', $r->firstElementChild->tagName, "\n";
$r->removeChild($r->firstElementChild);
echo (int) $r->childElementCount, '|', $r->firstElementChild->tagName, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><b/></r>');
$r2 = $d2->documentElement;
$r2->insertBefore($d2->createElement('a'), $r2->firstElementChild);
echo (int) $r2->childElementCount, '|', $r2->firstElementChild->tagName, '|', $r2->lastElementChild->tagName, "\n";

$d3 = new DOMDocument();
$d3->loadXML('<r><a/><b/></r>');
$r3 = $d3->documentElement;
$r3->replaceChild($d3->createElement('x'), $r3->firstElementChild);
echo (int) $r3->childElementCount, '|', $r3->firstElementChild->tagName, "\n";
