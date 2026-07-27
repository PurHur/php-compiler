<?php
// Issue #23979 — array literal packs dim-assign expression value before later mutations
// (Zend/zend_execute.c INIT_ARRAY / ADD_ARRAY_ELEMENT left-to-right).
$a = [1, 2, 3];
echo json_encode([$a[0] = 99, array_shift($a), $a]), "\n";

function f($x, $y, $z)
{
    echo "x=$x y=$y z=", json_encode($z), "\n";
}
$a = [1, 2, 3];
f($a[0] = 99, array_shift($a), $a);

$a = [1, 2, 3];
$r = [];
$r[] = ($a[0] = 99);
$r[] = array_shift($a);
$r[] = $a;
echo json_encode($r), "\n";
