--TEST--
Language: clone ($obj, with: [...]) named argument form (PHP 8.4, #12939)
--FILE--
<?php
class Point {
    public int $x = 1;
    public int $y = 2;
}

$p = new Point();
$q = clone ($p, with: ['x' => 9]);
echo $q->x, ',', $q->y, "\n";
--EXPECT--
9,2
