--TEST--
AOT: DOMNode::replaceChild updates childNodes/firstChild (#27216, php-src ext/dom/node.c)
--FILE--
<?php
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('root'));
$a = $r->appendChild($d->createElement('a'));
$b = $d->createElement('b');
$r->replaceChild($b, $a);
echo $r->childNodes->length, ':', $r->firstChild->nodeName, "\n";
?>
--EXPECT--
1:b
