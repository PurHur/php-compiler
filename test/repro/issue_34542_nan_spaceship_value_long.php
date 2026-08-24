<?php
// #34542 — boxed NAN <=> int (TYPE_VALUE⊙TYPE_NATIVE_LONG); leftover of #31967.
$n = NAN;
var_dump($n <=> 1);
var_dump(1 <=> $n);
$x = 1.5;
var_dump($x <=> 2);
var_dump(2 <=> $x);
