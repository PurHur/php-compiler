--TEST--
cloneNode on loadXML replaceChild/removeChild return (#35421)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$n = $d->createElement('c');
$old = $d->documentElement->firstChild;
$ret = $d->documentElement->replaceChild($n, $old);
echo 'replace_clone=', $ret->cloneNode(false)->tagName, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a/></r>');
$a = $d2->documentElement->firstChild;
$removed = $d2->documentElement->removeChild($a);
echo 'remove_clone=', $removed->cloneNode(false)->tagName, "\n";
--EXPECT--
replace_clone=a
remove_clone=a
