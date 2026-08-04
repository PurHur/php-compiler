--TEST--
AOT: replaceChild after appendChild return orphans old child (#27480, php-src ext/dom/node.c)
--FILE--
<?php
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('root'));
$a = $r->appendChild($d->createElement('a'));
$b = $d->createElement('b');
$r->replaceChild($b, $a);
echo 'len=', $r->childNodes->length, ' name=', $r->firstChild->nodeName,
    ' parent=', ($a->parentNode === null ? 'null' : 'set'), "\n";
?>
--EXPECT--
len=1 name=b parent=null
