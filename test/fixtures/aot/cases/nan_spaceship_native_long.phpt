--TEST--
AOT: boxed NAN <=> native long (TYPE_VALUE⊙NATIVE_LONG) (#34542)
--FILE--
<?php
declare(strict_types=1);

$n = NAN;
var_dump($n <=> 1);
var_dump(1 <=> $n);
var_dump($n <=> $n);
$i = 42;
var_dump($i <=> 7);
var_dump(7 <=> $i);
--EXPECT--
int(1)
int(1)
int(1)
int(1)
int(-1)
