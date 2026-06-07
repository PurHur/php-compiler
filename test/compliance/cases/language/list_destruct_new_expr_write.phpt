--TEST--
Language: list destructuring write slots on new property/array offset — compile-time fatal (#7286, #6691)
--FILE--
<?php
class C {
    public array $a = [0];
}
[(new C())->a[0]] = [1];
echo "ran\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Assignments can only happen to writable values in %s on line %d
