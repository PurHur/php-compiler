--TEST--
AOT: function-static string-only packed dim write (#32800)
--FILE--
<?php
function f_assign()
{
    static $a = ['x'];
    $a[0] = 'y';
    echo $a[0];
}
f_assign();
echo "\n";

function f_concat()
{
    static $a = ['x'];
    $a[0] .= 'y';
    echo $a[0];
}
f_concat();
echo '|';
f_concat();
echo "\n";

function f_int_ok()
{
    static $a = [1];
    $a[0] = 2;
    echo $a[0];
}
f_int_ok();
echo "\n";

function f_mixed_ok()
{
    static $a = [1, 'x'];
    $a[1] = 'y';
    echo $a[1];
}
f_mixed_ok();
echo "\n";

function f_runtime_ok()
{
    static $a;
    if (!isset($a)) {
        $a = ['x'];
    }
    $a[0] = 'y';
    echo $a[0];
}
f_runtime_ok();
echo "\n";
--EXPECT--
y
xy|xyy
2
y
y
--EXPECT_EXIT--
0
