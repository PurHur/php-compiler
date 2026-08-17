<?php
/**
 * Nested ?? on uninitialized typed properties.
 * Statement form ($o->x ?? "d") matches Zend; concat/call-arg form aborts remaining output.
 * Sibling: #31146 (statement ?? / ??=).
 */
error_reporting(E_ALL);

class C
{
    public int $x;
    public int $set = 7;
}

$o = new C();
echo "stmt=";
var_export($o->x ?? "d");
echo "\n";

echo "concat=" . var_export($o->x ?? "d", true) . "\n";
echo "setstmt=";
var_export($o->set ?? "d");
echo "\n";
echo "after\n";
