<?php
/** #35425 — AOT cloneNode after loadXML insertBefore(move) must keep moved tag. */
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$a = $d->documentElement->firstChild;
$b = $a->nextSibling;
$ret = $d->documentElement->insertBefore($b, $a);
echo 'ret=', $ret->tagName, "\n";
$c = $ret->cloneNode(false);
echo 'clone=', $c->tagName, "\n";
