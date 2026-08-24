<?php
// #34352 — loadXML must seed ParentNode element-nav slots (ext/dom/parentnode.c)
declare(strict_types=1);

$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$r = $d->documentElement;
$a = $r->firstChild;
$b = $a->nextSibling;

echo 'nextEl=', $a->nextElementSibling?->tagName ?? 'null', "\n";
echo 'firstEl=', $r->firstElementChild?->tagName ?? 'null', "\n";
echo 'count=', $r->childElementCount, "\n";
echo 'tag=', $r->firstElementChild->tagName, "\n";
echo 'prevEl=', $b->previousElementSibling?->tagName ?? 'null', "\n";
