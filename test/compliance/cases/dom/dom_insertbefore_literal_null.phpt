--TEST--
DOM: insertBefore($node, null) appends and preserves prior siblings (#26458)
--FILE--
<?php
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('root'));
$a = $d->createElement('a');
$b = $d->createElement('b');
$r->appendChild($a);
$ret = $r->insertBefore($b, null);
echo $r->childNodes->length, ' ', $r->C14N(), "\n";
echo $ret->nodeName, "\n";
// Variable null must match literal null (same php-src null-ref ≡ append path).
$c = $d->createElement('c');
$ref = null;
$r->insertBefore($c, $ref);
echo $r->childNodes->length, ' ', $r->C14N(), "\n";
?>
--EXPECT--
2 <root><a></a><b></b></root>
b
3 <root><a></a><b></b><c></c></root>
