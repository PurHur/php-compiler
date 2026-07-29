--TEST--
Spaceship operator in class constant expression (#24928)
--FILE--
<?php
class C {
    const LT = 1 <=> 2;
    const EQ = 2 <=> 2;
    const GT = 3 <=> 2;
    const STR = 'b' <=> 'a';
}
echo C::LT, C::EQ, C::GT, "\n";
echo C::STR, "\n";
--EXPECT--
-101
1
