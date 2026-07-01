--TEST--
Language: variadic by-reference parameters write back to caller (#14553, Zend/zend_execute.c)
--FILE--
<?php
function bump_first(&...$args): void {
    if (count($args) > 0) {
        $args[0] = 99;
    }
}
$x = 1;
bump_first($x);
echo $x, "\n";

function bump_first_int(int &...$args): void {
    if (count($args) > 0) {
        $args[0] = 99;
    }
}
$y = 1;
bump_first_int($y);
echo $y, "\n";

function bump_after_prefix(int $prefix, &...$args): void {
    if (count($args) > 0) {
        $args[0] = 99;
    }
}
$z = 1;
bump_after_prefix(0, $z);
echo $z, "\n";

$closure = function (&...$args): void {
    if (count($args) > 0) {
        $args[0] = 99;
    }
};
$w = 1;
$closure($w);
echo $w, "\n";
--EXPECT--
99
99
99
99
