<?php
enum E: int { case A = 1; case B = 2; }

$arr = [E::A];
$ref = &$arr[0];
echo "ref: ";
var_export($ref);
echo "\n";

echo "spread: ";
var_export([...([E::A, E::B])]);
echo "\n";

[$a, $b] = [E::A, E::B];
echo "list: ";
var_export([$a, $b]);
echo "\n";

$arr2 = [E::A, E::B];
foreach ($arr2 as &$v) { $v = E::B; }
unset($v);
echo "foreach_byref: ";
var_export($arr2);
echo "\n";
