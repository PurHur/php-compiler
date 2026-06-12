<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
    case B = 2;
}

$a = [E::A, E::B];
$b = [E::B];
$r = array_udiff($a, $b, static fn ($x, $y) => $x <=> $y);
echo count($r), "\n";
echo $r[0] === E::A ? "ok\n" : "bad\n";

$captured = null;
array_udiff([E::A], [E::B], static function ($x, $y) use (&$captured) {
    $captured = $x;

    return $x <=> $y;
});
var_export($captured instanceof E);
echo "\n";
