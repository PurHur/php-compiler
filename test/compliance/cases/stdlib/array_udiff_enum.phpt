--TEST--
stdlib array_udiff()/array_uintersect() preserve enum case objects in callbacks (#5637, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int { case A = 1; case B = 2; }

$r = array_udiff([E::A, E::B], [E::B], static fn ($x, $y) => $x <=> $y);
echo count($r), "\n";
echo $r[0] === E::A ? "udiff_ok\n" : "udiff_bad\n";

$captured = null;
array_udiff([E::A], [E::B], static function ($x, $y) use (&$captured) {
    $captured = $x;

    return $x <=> $y;
});
var_export($captured instanceof E);
echo "\n";

$i = array_uintersect([E::A], [E::A], static fn ($x, $y) => $x <=> $y);
echo count($i), "\n";
echo $i[0] === E::A ? "uintersect_ok\n" : "uintersect_bad\n";
--EXPECT--
1
udiff_ok
true
1
uintersect_ok
