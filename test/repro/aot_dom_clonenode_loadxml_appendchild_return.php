<?php
/** #35425 — AOT cloneNode after loadXML appendChild(move) must keep moved tag. */
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$moved = $d->documentElement->appendChild($d->documentElement->firstChild);
echo 'ret=', $moved->tagName, "\n";
$c = $moved->cloneNode(false);
echo 'clone=', $c->tagName, "\n";
