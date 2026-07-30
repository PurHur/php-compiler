--TEST--
Language: nullsafe ?-> chained property write — compile-time fatal (#25560)
--FILE--
<?php
class B { public int $x = 1; }
class A { public ?B $b = null; }
$a = new A();
$a?->b->x = 2;
echo "ran\n";
--EXPECT_EXIT--
255
