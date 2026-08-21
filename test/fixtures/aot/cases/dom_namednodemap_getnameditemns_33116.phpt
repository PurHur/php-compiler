--TEST--
AOT: DOMNamedNodeMap::getNamedItemNS on loadXML-seeded attributes (#33116)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r a="1" b="2"/>');
$m = $d->documentElement->attributes;
$n = $m->getNamedItemNS(null, 'b');
echo $n ? $n->value : 'null', PHP_EOL;
echo $m->getNamedItemNS(null, 'missing') === null ? 'miss' : 'hit', PHP_EOL;

$d2 = new DOMDocument();
$d2->loadXML('<r xmlns:p="urn:x" p:a="1" b="2"/>');
$m2 = $d2->documentElement->attributes;
$ns = $m2->getNamedItemNS('urn:x', 'a');
echo $ns ? $ns->value : 'null', PHP_EOL;
$plain = $m2->getNamedItemNS(null, 'b');
echo $plain ? $plain->value : 'null', PHP_EOL;
--EXPECT--
2
miss
1
2
