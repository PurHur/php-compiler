--TEST--
AOT: DOMNode::replaceChild($n, $n) is a no-op success (#22678 / #34709, php-src ext/dom/node.c)
--FILE--
<?php
$d = new DOMDocument();
$r = $d->createElement('r');
$d->appendChild($r);
$x = $d->createElement('x');
$r->appendChild($x);
$old = $r->replaceChild($x, $x);
echo 'ok old=' . $old->nodeName . "\n";
echo 'same=' . (int) $old->isSameNode($x) . "\n";
echo 'first=' . $r->firstChild->nodeName . "\n";
echo 'parent=' . ($x->parentNode === $r ? '1' : '0') . "\n";
?>
--EXPECT--
ok old=x
same=1
first=x
parent=1
