--TEST--
AOT: DOMNode::removeChild live childNodes length / orphan parent (#27475)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$a = $d->createElement('a');
$r->appendChild($a);
$r->removeChild($a);
echo $r->childNodes->length, "\n";
echo $a->parentNode !== null ? "parent\n" : "orphan\n";
--EXPECT--
0
orphan
