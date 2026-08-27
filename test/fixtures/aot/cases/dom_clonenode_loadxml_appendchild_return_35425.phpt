--TEST--
AOT: cloneNode on loadXML appendChild return uses moved tag (#35425)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$moved = $d->documentElement->appendChild($d->documentElement->firstChild);
echo 'ret=', $moved->tagName, "\n";
$c = $moved->cloneNode(false);
echo 'clone=', $c->tagName, "\n";
--EXPECT--
ret=a
clone=a
