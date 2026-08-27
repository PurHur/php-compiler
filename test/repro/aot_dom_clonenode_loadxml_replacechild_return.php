<?php
/** #35421 — AOT cloneNode on replaceChild() return after loadXML (leftover #35386). */
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$n = $d->createElement('c');
$old = $d->documentElement->firstChild;
$ret = $d->documentElement->replaceChild($n, $old);
echo 'ret=', $ret->tagName, "\n";
echo 'clone=', $ret->cloneNode(false)->tagName, "\n";
