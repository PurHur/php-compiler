--TEST--
AOT: function-static array dim ++ persists across calls (#32305)
--FILE--
<?php
function f()
{
    static $a = [1];
    $a[0]++;
    echo $a[0];
}
f();
echo '|';
f();
echo "\n";
$b = [4];
$b[0]++;
echo $b[0], "\n";
--EXPECT--
2|3
5
--EXPECT_EXIT--
0
