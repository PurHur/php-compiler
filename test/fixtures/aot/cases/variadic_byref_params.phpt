--TEST--
AOT: by-ref variadic (&...$args) write-back to caller (#27407, Zend/zend_execute.c)
--FILE--
<?php
function bump_first(&...$args): void {
    $args[0] = 99;
}
$x = 1;
bump_first($x);
echo $x, "\n";

function bump_first_int(int &...$args): void {
    $args[0] = 99;
}
$y = 1;
bump_first_int($y);
echo $y, "\n";

function bump_after_prefix(int $prefix, &...$args): void {
    $args[0] = 99;
}
$z = 1;
bump_after_prefix(0, $z);
echo $z, "\n";

function bump_two(&...$args): void {
    $args[0] = 9;
    $args[1] = 8;
}
$a = 1;
$b = 2;
bump_two($a, $b);
echo $a, ",", $b, "\n";
--EXPECT--
99
99
99
9,8
