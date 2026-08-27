<?php
// #35425 — loadXML insertBefore(move sibling) then cloneNode
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$a = $d->documentElement->firstChild;
$b = $a->nextSibling;
$ret = $d->documentElement->insertBefore($b, $a);
echo 'ret=', $ret->tagName, "\n";
$cl = $ret->cloneNode(false);
echo 'clone=', $cl->tagName, "\n";
