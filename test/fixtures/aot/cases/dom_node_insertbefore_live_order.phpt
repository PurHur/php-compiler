--TEST--
AOT: DOMNode::insertBefore live childNodes length/order/parent (#27449)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$a = $d->createElement('a');
$b = $d->createElement('b');
$r->appendChild($a);
$r->insertBefore($b, $a);
echo $r->childNodes->length, "\n";
echo $r->firstChild->nodeName, "\n";
echo $b->parentNode !== null ? "parent\n" : "orphan\n";
--EXPECT--
2
b
parent
