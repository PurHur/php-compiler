--TEST--
AOT: normalize loadXML text/comment/text (#33582)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r>a<!--c-->b</r>');
$r = $d->documentElement;
$r->normalize();
echo $r->childNodes->length, ' ', $r->firstChild->nodeValue, "\n";
?>
--EXPECT--
3 a
