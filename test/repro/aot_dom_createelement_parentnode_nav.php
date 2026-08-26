<?php

// #35007 — AOT createElement / DocumentFragment ParentNode nav must match Zend.
$d = new DOMDocument();
$e = $d->createElement('r');
echo 'bare_count=', (int) $e->childElementCount, "\n";
echo 'bare_first=', ($e->firstElementChild === null ? 'NULL' : 'OBJ'), "\n";

$d->appendChild($e);
$a = $d->createElement('a');
$b = $d->createElement('b');
$e->appendChild($a);
$e->appendChild($b);
echo 'first=', $e->firstElementChild->tagName, "\n";
echo 'last=', $e->lastElementChild->tagName, "\n";
echo 'count=', (int) $e->childElementCount, "\n";
echo 'a.next=', $a->nextElementSibling->tagName, "\n";
echo 'b.prev=', $b->previousElementSibling->tagName, "\n";

$f = $d->createDocumentFragment();
$f->appendChild($d->createElement('x'));
$f->appendChild($d->createElement('y'));
echo 'frag_count=', (int) $f->childElementCount, "\n";
echo 'frag_first=', $f->firstElementChild->tagName, "\n";
