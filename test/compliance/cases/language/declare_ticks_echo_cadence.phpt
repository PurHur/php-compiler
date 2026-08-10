--TEST--
declare(ticks=1) + echo fires per Zend ECHO cadence (#30010)
--FILE--
<?php
declare(ticks=1);
$n = 0;
register_tick_function(function () use (&$n) {
    $n++;
});
echo "a";
echo "b";
echo "c";
echo "n=$n\n";

$n = 0;
echo "x", "y", "z";
echo "comma=$n\n";

$n = 0;
echo strtoupper("a"), "b", "c";
echo "call=$n\n";

function _ticks_foo($x) {
    return $x;
}
function _ticks_bar() {
    return "A";
}
$n = 0;
echo _ticks_foo(_ticks_bar()), "x", "y";
echo "nested=$n\n";
--EXPECT--
abcn=4
xyzcomma=4
Abccall=4
Axynested=4
