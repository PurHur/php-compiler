--TEST--
AOT: DOMNamedNodeMap::getNamedItem on loadXML-seeded attributes (#33107)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r a="1" b="2"/>');
$m = $d->documentElement->attributes;
$n = $m->getNamedItem('b');
echo $n ? $n->value : 'null', PHP_EOL;
echo $m->getNamedItem('a')->name, PHP_EOL;
echo $m->getNamedItem('missing') === null ? 'miss' : 'hit', PHP_EOL;
--EXPECT--
2
a
miss
