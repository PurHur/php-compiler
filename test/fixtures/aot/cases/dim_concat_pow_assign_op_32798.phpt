--TEST--
AOT: dim .= / **= hydrate FETCH_DIM_W (#32798 leftover #32789)
--FILE--
<?php
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

$d = [1];
$d[0] += 1;
echo $d[0], "\n";
--EXPECT--
xy
ab|abb
8
4|16
2
--EXPECT_EXIT--
0
