--TEST--
AOT: function-static packed string $a[0]='y' / .= persists (#32800)
--FILE--
<?php
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
y
y
xy
xyy
--EXPECT_EXIT--
0
