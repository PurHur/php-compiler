--TEST--
language array literal dim-assign then array_shift — pack assign result (#23979, Zend/zend_execute.c)
--FILE--
<?php
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
?>
--EXPECT--
[99,99,[2,3]]
x=99 y=99 z=[2,3]
[99,99,[2,3]]
