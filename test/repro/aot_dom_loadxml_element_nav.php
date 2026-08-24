<?php
// #34352 — loadXML must seed ParentNode / NonDocumentTypeChildNode element-nav slots (ext/dom/parentnode.c)
declare(strict_types=1);

$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$r = $d->documentElement;
$a = $r->firstChild;
$b = $a->nextSibling;

echo 'nextSibling=', $b->nodeName, "\n";
echo 'nextElementSibling=', ($a->nextElementSibling?->nodeName ?? 'null'), "\n";
echo 'firstElementChild=', $r->firstElementChild->tagName, "\n";
echo 'childElementCount=', $r->childElementCount, "\n";
