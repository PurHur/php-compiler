<?php
// #35012 — ChildNode::after() append-tail must update ParentNode element-nav (php-src parentnode.c).
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$r = $d->documentElement;
$a = $r->firstElementChild;
$b = $d->createElement('b');
$a->after($b);
echo $r->childElementCount . '|' . $r->firstElementChild->tagName . '|' . $r->lastElementChild->tagName . "\n";
