--TEST--
DOM getElementById duplicate setIdAttribute first wins (#25275, ext/dom/document.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a id="x"/><b id="x"/></r>');
$a = $d->documentElement->firstChild;
$b = $a->nextSibling;
$a->setIdAttribute('id', true);
$b->setIdAttribute('id', true);
echo $d->getElementById('x')->nodeName, "\n";
--EXPECT--
a
