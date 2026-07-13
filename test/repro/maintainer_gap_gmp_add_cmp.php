<?php
$a = gmp_init('999999999999999999999');
$b = gmp_init('1');
echo gmp_strval(gmp_add($a, $b)), "\n";
echo gmp_cmp($a, $b), "\n";
