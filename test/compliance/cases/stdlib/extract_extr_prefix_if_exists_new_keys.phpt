--TEST--
stdlib extract() EXTR_PREFIX_IF_EXISTS imports absent keys unprefixed (#24330, ext/standard/array.c php_extract)
--FILE--
<?php
$a = 1;
$arr = ['a' => 2, 'b' => 3];
$n = extract($arr, EXTR_PREFIX_IF_EXISTS, 'p');
echo "n=$n a=$a p_a=", $p_a ?? 'unset', " b=", $b ?? 'unset', "\n";

function repro_fn(): void {
    $a = 1;
    $arr = ['a' => 2, 'b' => 3];
    $n = extract($arr, EXTR_PREFIX_IF_EXISTS, 'p');
    echo "fn n=$n a=$a p_a=", $p_a ?? 'unset', " b=", $b ?? 'unset', "\n";
}
repro_fn();

function repro_fresh(): void {
    $arr = ['a' => 2, 'b' => 3];
    $n = extract($arr, EXTR_PREFIX_IF_EXISTS, 'p');
    echo "fresh n=$n a=", $a ?? 'unset', " b=", $b ?? 'unset', "\n";
}
repro_fresh();

function repro_uninit_b(): void {
    $arr = ['b' => 3];
    $n = extract($arr, EXTR_PREFIX_IF_EXISTS, 'p');
    echo "uninit n=$n b=", $b ?? 'unset', "\n";
}
repro_uninit_b();
--EXPECT--
n=2 a=1 p_a=2 b=3
fn n=2 a=1 p_a=2 b=3
fresh n=2 a=2 b=3
uninit n=1 b=3
