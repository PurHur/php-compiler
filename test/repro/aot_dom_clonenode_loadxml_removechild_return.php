<?php
/** #35421 — AOT cloneNode on removeChild() return after loadXML (leftover #35386). */
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$a = $d->documentElement->firstChild;
$ret = $d->documentElement->removeChild($a);
echo 'ret=', $ret->tagName, "\n";
echo 'clone=', $ret->cloneNode(false)->tagName, "\n";
