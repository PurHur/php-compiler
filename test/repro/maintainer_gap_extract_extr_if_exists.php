<?php
// Only keys that already hold values should import under EXTR_IF_EXISTS.
// Zend: n=2 a=2 b_unset c=4
// VM:   n=3 a=2 b=3 c=4  (imports $b because a later echo allocates the slot)
$a = 1;
$c = 9;
$arr = ['a' => 2, 'b' => 3, 'c' => 4];
$n = extract($arr, EXTR_IF_EXISTS);
echo "n=$n a=$a";
echo " b=", $b ?? 'unset';
echo " c=$c\n";
