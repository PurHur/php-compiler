<?php
/** Repro #20605 — DOMAttr::$specified always true (php-src ext/dom/attr.c). */
$d = new DOMDocument();
$d->loadXML('<r a="1"/>');
$a = $d->documentElement->getAttributeNode('a');
var_export(property_exists($a, 'specified'));
echo "\n";
var_export(isset($a->specified));
echo "\n";
var_export($a->specified);
echo "\n";
$b = $d->createAttribute('x');
var_export($b->specified);
echo "\n";
try {
    $a->specified = false;
    echo "write_ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
