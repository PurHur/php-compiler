<?php
// Existing keys get prefix; non-existing keys import unprefixed (php_extract).
// Zend: n=2 a=1 p_a=2 b=3
// VM:   n=1 a=1 p_a=2 b_unset
$a = 1;
$arr = ['a' => 2, 'b' => 3];
$n = extract($arr, EXTR_PREFIX_IF_EXISTS, 'p');
echo "n=$n a=$a p_a=", $p_a ?? 'unset', " b=", $b ?? 'unset', "\n";
