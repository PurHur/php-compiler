<?php
// #35425 — loadXML appendChild(move firstChild) then cloneNode
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$a = $d->documentElement->firstChild;
$ret = $d->documentElement->appendChild($a);
echo 'ret=', $ret->tagName, "\n";
$cl = $ret->cloneNode(false);
echo 'clone=', $cl->tagName, "\n";
