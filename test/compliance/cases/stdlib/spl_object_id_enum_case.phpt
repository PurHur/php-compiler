--TEST--
stdlib spl_object_id() — stable enum case singleton handle (#8941)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = 2;
}
$a1 = spl_object_id(E::A);
$a2 = spl_object_id(E::A);
$b = spl_object_id(E::B);
echo ($a1 === $a2) ? "stable\n" : "unstable\n";
echo ($a1 !== $b) ? "distinct\n" : "equal\n";
echo ($a1 > 0 && $b > 0) ? "positive\n" : "nonpositive\n";
--EXPECT--
stable
distinct
positive
