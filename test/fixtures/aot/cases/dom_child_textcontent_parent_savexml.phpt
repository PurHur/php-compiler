--TEST--
AOT: child textContent write must refresh parent saveXML (#33293, re-#23892)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a>old</a><b>keep</b></r>');
$a = $d->documentElement->firstChild;
$a->textContent = 'new';
echo 'a_tc=', var_export($a->textContent, true), "\n";
echo 'save_a=', $d->saveXML($a), "\n";
echo 'save_r=', $d->saveXML($d->documentElement), "\n";
echo 'save=', trim($d->saveXML()), "\n";
?>
--EXPECT--
a_tc='new'
save_a=<a>new</a>
save_r=<r><a>new</a><b>keep</b></r>
save=<?xml version="1.0"?>
<r><a>new</a><b>keep</b></r>
