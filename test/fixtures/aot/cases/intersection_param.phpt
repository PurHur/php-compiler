--TEST--
Intersection parameter type (A&B) AOT call-site check (#3077)
--FILE--
<?php
interface A {}
interface B {}
class C implements A, B {}
function need(A&B $x): int {
    return 1;
}
echo need(new C());
?>
--EXPECT--
1
