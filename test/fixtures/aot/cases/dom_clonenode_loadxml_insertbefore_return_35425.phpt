--TEST--
AOT: cloneNode on loadXML insertBefore return uses moved tag (#35425)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$a = $d->documentElement->firstChild;
$b = $a->nextSibling;
$ret = $d->documentElement->insertBefore($b, $a);
echo 'ret=', $ret->tagName, "\n";
$c = $ret->cloneNode(false);
echo 'clone=', $c->tagName, "\n";
--EXPECT--
ret=b
clone=b
