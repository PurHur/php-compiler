--TEST--
Language: clone ($obj, with: [...]) user-script AOT (#19130, PHP 8.4)
--SKIPIF--
<?php
if (!getenv('PHP_COMPILER_PROFILE') || '8.4' !== getenv('PHP_COMPILER_PROFILE')) {
    die('skip requires PHP_COMPILER_PROFILE=8.4 clone-with gate');
}
--FILE--
<?php
class Point {
    public int $x = 1;
    public int $y = 2;
}
$p = new Point();
$q = clone ($p, with: ['x' => 9]);
echo $q->x, ',', $q->y;
--EXPECT--
9,2
