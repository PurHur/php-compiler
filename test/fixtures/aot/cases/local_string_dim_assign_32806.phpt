--TEST--
AOT: local $s[i]='Z' mutates string; function-static array dim stays HT (#32806 / #32800)
--FILE--
<?php
$s = 'abc';
$s[1] = 'Z';
echo $s, "\n";
echo gettype($s), "\n";

function assign_slot(): void
{
    static $a = ['x'];
    $a[0] = 'y';
    echo $a[0], "\n";
}
assign_slot();
assign_slot();

function concat_slot(): void
{
    static $a = ['x'];
    $a[0] .= 'y';
    echo $a[0], "\n";
}
concat_slot();
concat_slot();
--EXPECT--
aZc
string
y
y
xy
xyy
--EXPECT_EXIT--
0
