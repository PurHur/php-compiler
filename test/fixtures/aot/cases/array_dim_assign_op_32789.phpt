--TEST--
AOT: array dim assign-op reads current FETCH_DIM_W element (#32789)
--FILE--
<?php
$a = [1];
$a[0] += 1;
echo $a[0], "\n";

function f(): void
{
    static $a = [1];
    $a[0] += 1;
    echo $a[0];
}
f();
echo '|';
f();
echo "\n";

$b = ['k' => 1];
$b['k'] += 1;
echo $b['k'], "\n";

$c = [3];
$c[0] -= 1;
echo $c[0], "\n";

$d = [2];
$d[0] *= 3;
echo $d[0], "\n";

function g(): void
{
    static $a = [1];
    $a[0]++;
    echo $a[0];
}
g();
echo '|';
g();
echo "\n";
--EXPECT--
2
2|3
2
2
6
2|3
--EXPECT_EXIT--
0
