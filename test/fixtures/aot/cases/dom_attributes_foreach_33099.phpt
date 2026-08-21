--TEST--
AOT: foreach over loadXML-seeded element attributes NamedNodeMap (#33099)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r a="1" b="2"/>');
$o = [];
foreach ($d->documentElement->attributes as $n) {
    $o[] = $n->name;
}
echo implode(',', $o), PHP_EOL;
--EXPECT--
a,b
