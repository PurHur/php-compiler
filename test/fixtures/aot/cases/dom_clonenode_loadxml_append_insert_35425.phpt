--TEST--
cloneNode on loadXML appendChild/insertBefore return (#35425)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$a = $d->documentElement->firstChild;
$ret = $d->documentElement->appendChild($a);
echo 'append_clone=', $ret->cloneNode(false)->tagName, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a/><b/></r>');
$a2 = $d2->documentElement->firstChild;
$b2 = $a2->nextSibling;
$ret2 = $d2->documentElement->insertBefore($b2, $a2);
echo 'insert_clone=', $ret2->cloneNode(false)->tagName, "\n";
--EXPECT--
append_clone=a
insert_clone=b
