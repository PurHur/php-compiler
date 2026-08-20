--TEST--
AOT: array dim assign-op hydrates FETCH_DIM_W before read (#32789)
--FILE--
<?php
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
--EXPECT--
2
2|3
2|3
2
--EXPECT_EXIT--
0
