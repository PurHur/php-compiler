--TEST--
AOT: boxed NAN/float <=> int (TYPE_VALUE⊙NATIVE_LONG) (#34542, re-#31967)
--FILE--
<?php
$n = NAN;
var_dump($n <=> 1);
var_dump(1 <=> $n);
$x = 1.5;
var_dump($x <=> 2);
var_dump(2 <=> $x);
--EXPECT--
int(1)
int(1)
int(-1)
int(1)
