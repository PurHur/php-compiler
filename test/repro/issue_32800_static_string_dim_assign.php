<?php
/**
 * #32800 — function-static string array dim assign must not SEGV under AOT.
 */
function store()
{
    static $a = ['x'];
    $a[0] = 'y';
    echo $a[0];
}
store();
echo '|';
store();
echo "\n";

function concat()
{
    static $a = ['x'];
    $a[0] .= 'y';
    echo $a[0];
}
concat();
echo '|';
concat();
echo "\n";

function intPeer()
{
    static $a = [1];
    $a[0] += 1;
    echo $a[0];
}
intPeer();
echo '|';
intPeer();
echo "\n";

$local = ['x'];
$local[0] = 'y';
echo $local[0], "\n";
