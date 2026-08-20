<?php
/**
 * #32789 / leftover #32305 — FETCH_DIM_W assign-op must read the live hashtable slot.
 * AOT prepareIndexWrite left an empty orphan box so `$a[0]+=1` always saw 0.
 */
$a = [1];
$a[0] += 1;
echo $a[0], "\n";

function f()
{
    static $a = [1];
    $a[0] += 1;
    echo $a[0];
}
f();
echo '|';
f();
echo "\n";

function g()
{
    static $a = ['k' => 1];
    $a['k'] += 1;
    echo $a['k'];
}
g();
echo '|';
g();
echo "\n";

$b = [1];
$k = 0;
$b[$k] += 1;
echo $b[0], "\n";
