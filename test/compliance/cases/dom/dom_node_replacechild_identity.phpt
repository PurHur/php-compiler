--TEST--
DOMNode::replaceChild($n, $n) is a no-op success (#22678, php-src ext/dom/node.c)
--FILE--
<?php
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('r'));
$x = $d->createElement('x');
$r->appendChild($x);
$old = $r->replaceChild($x, $x);
echo 'ok old=' . $old->nodeName . ' len=' . $r->childNodes->length . "\n";
echo 'same=' . ($old === $x ? '1' : '0') . "\n";
echo 'parent=' . $x->parentNode->nodeName . "\n";
?>
--EXPECT--
ok old=x len=1
same=1
parent=r
