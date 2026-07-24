--TEST--
declare(ticks=N) braced body fires per Zend statement cadence (#22840)
--FILE--
<?php
$n = 0;
register_tick_function(function () use (&$n) {
    $n++;
});
declare(ticks=1) {
    $a = 1;
    $b = 2;
    $c = 3;
}
echo "n=$n\n";
$n = 0;
declare(ticks=2) {
    $a = 1;
    $b = 2;
    $c = 3;
    $d = 4;
}
echo "n2=$n\n";
$n = 0;
$x = 0;
declare(ticks=1) {
    $x += 1;
    $x += 1;
    $x += 1;
}
echo "n3=$n x=$x\n";
--EXPECT--
n=3
n2=2
n3=3 x=3
