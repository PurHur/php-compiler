--TEST--
DOMAttr::$specified always true + read-only (#20605, ext/dom/attr.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r a="1"/>');
$a = $d->documentElement->getAttributeNode('a');
echo property_exists($a, 'specified') ? "exists_ok\n" : "exists_fail\n";
echo isset($a->specified) ? "isset_ok\n" : "isset_fail\n";
echo ($a->specified === true) ? "read_ok\n" : "read_fail\n";
$b = $d->createAttribute('x');
echo ($b->specified === true) ? "create_ok\n" : "create_fail\n";
$d->documentElement->setAttribute('y', '2');
$y = $d->documentElement->getAttributeNode('y');
echo ($y->specified === true) ? "set_ok\n" : "set_fail\n";
try {
    $a->specified = false;
    echo "write_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
exists_ok
isset_ok
read_ok
create_ok
set_ok
Cannot write read-only property DOMAttr::$specified
