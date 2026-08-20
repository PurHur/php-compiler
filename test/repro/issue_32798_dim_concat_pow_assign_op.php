<?php
// AOT: dim .= / **= must hydrate FETCH_DIM_W (#32798 leftover of #32789).
$a = ['x'];
$a[0] .= 'y';
echo $a[0], "\n";

function c(): void
{
    static $a = ['p' => 'a'];
    $a['p'] .= 'b';
    echo $a['p'];
}
c();
echo '|';
c();
echo "\n";

$b = [2];
$b[0] **= 3;
echo $b[0], "\n";

function p(): void
{
    static $a = [2];
    $a[0] **= 2;
    echo $a[0];
}
p();
echo '|';
p();
echo "\n";

// Peer #32789 — += stays green
$d = [1];
$d[0] += 1;
echo $d[0], "\n";
