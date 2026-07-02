--TEST--
stdlib DOMNode replaceChild/insertBefore/removeChild (#14394, ext/dom/node.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<a><b/><c/></a>');
$a = $d->documentElement;
$b = $a->firstChild;
$c = $a->lastChild;
$old = $a->replaceChild($c, $b);
echo $a->firstChild->nodeName, ' ', $old->nodeName, "\n";
$new = $d->createElement('d');
$a->insertBefore($new, $c);
echo $a->firstChild->nodeName, ' ', $a->lastChild->nodeName, "\n";
$removed = $a->removeChild($c);
echo $removed->nodeName, ' ', $a->firstChild->nodeName, "\n";
?>
--EXPECT--
c b
d c
c d
