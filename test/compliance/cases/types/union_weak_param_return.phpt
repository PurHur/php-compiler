--TEST--
Weak-mode scalar union param/return coercion matches Zend (#19525)
--FILE--
<?php
function g(int|string $x): void {
    var_export($x);
    echo ' ', gettype($x), "\n";
}
echo '1.5: ';
g(1.5);
echo 'true: ';
g(true);
echo '1.0: ';
g(1.0);

function h(): int|string {
    return 1.5;
}
echo 'ret: ';
var_export(h());
echo "\n";

function f(float|string $x): void {
    var_export($x);
    echo ' ', gettype($x), "\n";
}
echo 'float int: ';
f(1);
echo 'float true: ';
f(true);

function b(bool|array $x): void {
    var_export($x);
    echo ' ', gettype($x), "\n";
}
echo 'bool 1: ';
b(1);
echo 'bool 0: ';
b(0);
echo 'bool empty: ';
b('');

$v = 1.5;
echo 'rt: ';
g($v);
?>
--EXPECT--
1.5: 1 integer
true: 1 integer
1.0: 1 integer
ret: 1
float int: 1.0 double
float true: 1.0 double
bool 1: true boolean
bool 0: false boolean
bool empty: false boolean
rt: 1 integer
