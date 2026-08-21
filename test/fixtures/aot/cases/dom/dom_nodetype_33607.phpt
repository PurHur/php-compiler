--TEST--
AOT: DOMNode::$nodeType seeded on create* / Document (#33607, ext/dom/node.c)
--FILE--
<?php
$d = new DOMDocument();
$e = $d->createElement('root');
$a = $d->createAttribute('k');
$a->value = 'v';
$e->setAttributeNode($a);
$t = $d->createTextNode('x');
$c = $d->createComment('y');
echo $d->nodeType, '|', $e->nodeType, '|', $a->nodeType, '|', $t->nodeType, '|', $c->nodeType, "\n";
--EXPECT--
9|1|2|3|8
