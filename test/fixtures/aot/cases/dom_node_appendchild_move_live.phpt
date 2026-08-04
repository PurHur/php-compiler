--TEST--
AOT: DOMNode::appendChild move live childNodes length/order (#27476)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$a = $d->createElement('a');
$b = $d->createElement('b');
$r->appendChild($a);
$r->appendChild($b);
$r->appendChild($a); // move
echo $r->childNodes->length, "\n";
echo $r->firstChild->nodeName, "\n";
echo $r->lastChild->nodeName, "\n";
--EXPECT--
2
b
a
